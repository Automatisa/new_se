<?php

if (!class_exists('fpm_pool_manager')) {

/**
 * Gestiona los pools PHP-FPM por dominio.
 *
 * Genera un fichero de pool en POOL_DIR por cada vhost activo,
 * con los valores php_value leídos de x_domain_php.
 * Los sockets generados se usan en los vhosts Apache via SetHandler.
 */
class fpm_pool_manager
{
    const POOL_DIR   = '/usr/local/etc/php-fpm.d/';   // pools de la versión del sistema (por defecto)
    const SOCKET_DIR = '/var/run/php-fpm/';
    const PREFIX     = 'bulwark_';

    /**
     * Versiones de PHP disponibles para asignar por dominio.
     * '' = versión del sistema (pkg estándar, /usr/local). 'NN' = /usr/local/phpNN (PREFIX propio,
     * compilada con php_multi_build.sh e instalada con php_version_install.sh).
     * Se AUTODETECTA por la presencia del binario FPM, así no hay ajuste que mantener ni drift.
     *
     * @return array<string,string> versión => directorio de pools (POOL_DIR)
     */
    static function InstalledVersions()
    {
        $out = array('' => self::POOL_DIR); // el sistema siempre está
        foreach (glob('/usr/local/php[0-9][0-9]/sbin/php-fpm') as $bin) {
            if (preg_match('#/usr/local/php([0-9]{2})/sbin/php-fpm$#', $bin, $m)) {
                $out[$m[1]] = '/usr/local/php' . $m[1] . '/etc/php-fpm.d/';
            }
        }
        return $out;
    }

    /** Nombre del servicio rc.d de FPM para una versión ('' = php_fpm del sistema). */
    static function serviceForVersion($v)
    {
        return ($v === '' || $v === null) ? 'php_fpm' : ('php' . $v . '_fpm');
    }

    /**
     * Regenera todos los pools FPM desde la BD y recarga FPM.
     * Debe llamarse desde el daemon (root) o desde un contexto con doas.
     *
     * @return int Número de pools activos generados
     */
    static function Regenerate()
    {
        global $zdbh;

        $hostedDir = ctrl_options::GetSystemOption('hosted_dir');

        try {
            $sql = $zdbh->prepare("
                SELECT v.vh_directory_vc, u.ac_user_vc AS username,
                       COALESCE(p.dp_php_version_vc, '')                            AS php_version,
                       COALESCE(p.dp_upload_max_vc,    q.qt_php_upload_vc,  '50M')  AS upload_max_raw,
                       COALESCE(p.dp_post_max_vc,      q.qt_php_post_vc,    '50M')  AS post_max_raw,
                       COALESCE(p.dp_memory_limit_vc,  q.qt_php_memory_vc,  '128M') AS memory_limit_raw,
                       COALESCE(p.dp_max_exec_in,      q.qt_php_exec_in,    30)     AS max_exec_raw,
                       COALESCE(p.dp_max_input_in,     q.qt_php_maxinput_in,60)     AS max_input_raw,
                       COALESCE(p.dp_display_errors_in, 0)                          AS display_errors,
                       COALESCE(p.dp_timezone_vc, '')                               AS timezone,
                       COALESCE(p.dp_max_input_vars_in, 1000)                        AS max_input_vars,
                       COALESCE(p.dp_opcache_in, 1)                                  AS opcache,
                       COALESCE(q.qt_php_memory_vc,  '128M') AS pkg_memory,
                       COALESCE(q.qt_php_upload_vc,  '50M')  AS pkg_upload,
                       COALESCE(q.qt_php_post_vc,    '50M')  AS pkg_post,
                       COALESCE(q.qt_php_exec_in,    30)     AS pkg_exec,
                       COALESCE(q.qt_php_maxinput_in,60)     AS pkg_maxinput
                FROM x_vhosts v
                JOIN x_accounts u ON v.vh_acc_fk = u.ac_id_pk
                LEFT JOIN x_domain_php p ON p.dp_vhost_fk = v.vh_id_pk
                LEFT JOIN x_packages pk ON pk.pk_id_pk = u.ac_package_fk AND pk.pk_deleted_ts IS NULL
                LEFT JOIN x_quotas q ON q.qt_package_fk = pk.pk_id_pk
                WHERE v.vh_deleted_ts IS NULL AND v.vh_type_in IN (1, 2)
            ");
            $sql->execute();
            $vhosts = $sql->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // La tabla x_domain_php o x_quotas (o la columna php_version) aún no existe; no hacer nada
            return 0;
        }

        // Directorios de pool gestionados (sistema + cada versión instalada). Para cada uno llevamos
        // la lista de pools "vivos" (para limpiar obsoletos) y si cambió algo (para recargar SOLO ese
        // master FPM). El socket es el MISMO sea cual sea la versión, así Apache no se toca nunca.
        $installed   = self::InstalledVersions();
        $activeByDir = array();                 // dir => [ 'name.conf', ... ]
        $changedDirs = array();                 // dir => true (union de ganados+perdidos)
        $gainedDirs  = array();                 // dir => true: se (re)escribió un pool aquí
        $lostDirs    = array();                 // dir => true: se eliminó un pool aquí
        foreach ($installed as $dir) { $activeByDir[$dir] = array(); }

        foreach ($vhosts as $vh) {
            $name   = self::PREFIX . preg_replace('/[^a-zA-Z0-9_]/', '_', $vh['vh_directory_vc']);
            $socket = self::SOCKET_DIR . $name . '.sock';

            // Versión pedida; si no está instalada, caer a la del sistema ('').
            $ver     = preg_match('/^[0-9]{2}$/', (string)$vh['php_version']) ? $vh['php_version'] : '';
            if (!isset($installed[$ver])) { $ver = ''; }
            $poolDir = $installed[$ver];
            $base   = rtrim($hostedDir, '/') . '/' . $vh['username'] . '/' . ctrl_options::DOMAINS_SUBDIR . '/' . $vh['vh_directory_vc'];
            $tmp    = $base . '/tmp';
            $errors = $vh['display_errors'] ? 'on' : 'off';

            // Usar usuario de sistema h_USERNAME si existe, fallback a www
            $sysuser    = 'h_' . $vh['username'];
            $fpm_user   = self::sysuserExists($sysuser) ? $sysuser : 'www';

            // Aplicar cap del paquete: el valor efectivo nunca supera el límite del paquete.
            $upload_max   = self::capSize($vh['upload_max_raw'],   $vh['pkg_upload']);
            $post_max     = self::capSize($vh['post_max_raw'],     $vh['pkg_post']);
            $memory_limit = self::capSize($vh['memory_limit_raw'], $vh['pkg_memory']);
            $max_exec     = min((int)$vh['max_exec_raw'],   (int)$vh['pkg_exec']);
            $max_input    = min((int)$vh['max_input_raw'],  (int)$vh['pkg_maxinput']);

            $conf  = "[{$name}]\n";
            $conf .= "user = {$fpm_user}\n";
            // El grupo del worker es el GRUPO PROPIO del usuario, NO www: si fuera www, el
            // proceso (y sus hijos exec) heredaría el grupo www y podría LEER los ficheros
            // h_user:www 0640 de OTROS inquilinos (los hijos exec no respetan open_basedir).
            // Apache (que sí es www) sigue sirviendo los estáticos; el worker no necesita www.
            $conf .= "group = {$fpm_user}\n";
            $conf .= "listen = {$socket}\n";
            $conf .= "listen.owner = www\n";
            $conf .= "listen.group = www\n";
            $conf .= "listen.mode = 0660\n";
            $conf .= "pm = ondemand\n";
            $conf .= "pm.max_children = 10\n";
            $conf .= "pm.process_idle_timeout = 60s\n";
            $conf .= "pm.max_requests = 500\n";
            // php_admin_value/flag: el cliente NO puede sobreescribir con .user.ini.
            // Los valores están caps al límite máximo del paquete asignado.
            $conf .= "php_admin_value[upload_max_filesize] = {$upload_max}\n";
            $conf .= "php_admin_value[post_max_size] = {$post_max}\n";
            $conf .= "php_admin_value[memory_limit] = {$memory_limit}\n";
            $conf .= "php_admin_value[max_execution_time] = {$max_exec}\n";
            $conf .= "php_admin_value[max_input_time] = {$max_input}\n";
            $conf .= "php_admin_flag[display_errors] = {$errors}\n";
            // Directivas fijas adicionales (por dominio, aplicadas a la versión elegida):
            //  - date.timezone: solo si es una zona horaria VÁLIDA de PHP (si no, se omite y usa la
            //    del php.ini de la versión). Evita inyección: se compara contra la lista canónica.
            $tz = (string)$vh['timezone'];
            if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                $conf .= "php_admin_value[date.timezone] = {$tz}\n";
            }
            //  - max_input_vars: entero acotado a un rango sano.
            $miv = min(100000, max(1, (int)$vh['max_input_vars']));
            $conf .= "php_admin_value[max_input_vars] = {$miv}\n";
            //  - opcache.enable: on/off (inofensivo si la extensión no está cargada).
            $opc = ((int)$vh['opcache'] === 1) ? '1' : '0';
            $conf .= "php_admin_value[opcache.enable] = {$opc}\n";
            $conf .= "php_admin_value[session.save_path] = {$tmp}/\n";
            $conf .= "php_admin_value[upload_tmp_dir] = {$tmp}/\n";
            // open_basedir SIN el /tmp compartido: cada dominio usa su propio tmp/ (aislado);
            // session.save_path y upload_tmp_dir ya apuntan a ese tmp por dominio.
            $conf .= "php_admin_value[open_basedir] = {$base}/public_html/:{$base}/tmp/\n";
            $conf .= "php_admin_value[error_log] = {$base}/logs/php-error.log\n";

            $file = $poolDir . $name . '.conf';
            // Solo escribir y marcar cambio si el contenido es diferente al disco
            if (!file_exists($file) || file_get_contents($file) !== $conf) {
                file_put_contents($file, $conf);
                chmod($file, 0644);
                $changedDirs[$poolDir] = true;
                $gainedDirs[$poolDir]  = true;   // este master GANA/actualiza un pool -> actúa al FINAL
            }
            $activeByDir[$poolDir][] = $name . '.conf';

            // Asegurar que el directorio del dominio pertenece al sysuser correcto.
            // El panel crea los directorios como bulwark:www (el panel corre como bulwark); el daemon
            // corre como root y puede corregirlo para que h_USERNAME sea el dueño.
            if ($fpm_user !== 'www' && is_dir($base)) {
                self::chownDomainDir($base, $fpm_user);
            }
        }

        // Eliminar pools obsoletos en CADA directorio gestionado. Un dominio que cambia de versión
        // deja de aparecer en el dir viejo (se borra allí) y aparece en el nuevo. El socket es el
        // mismo nombre, pero un dominio solo tiene su pool en UN dir a la vez, así no hay colisión.
        $totalActive = 0;
        foreach ($installed as $dir) {
            $keep = $activeByDir[$dir];
            $totalActive += count($keep);
            foreach (glob($dir . self::PREFIX . '*.conf') as $f) {
                if (!in_array(basename($f), $keep, true)) {
                    @unlink($f);
                    @unlink(self::SOCKET_DIR . basename($f, '.conf') . '.sock');
                    $changedDirs[$dir] = true;
                    $lostDirs[$dir]    = true;   // este master PIERDE un pool -> actúa PRIMERO
                }
            }
        }

        // Recargar los masters FPM cuyos pools cambiaron, EN DOS RONDAS. La ruta del socket es
        // COMPARTIDA entre versiones (bulwark_<dir>.sock), así que al mover un dominio de master hay
        // que ordenar: primero el master que PIERDE el pool (libera/cierra el socket compartido) y
        // AL FINAL el que lo GANA (crea el socket) -> sin carrera y sin doble restart.
        //   - php_fpm del SISTEMA: reload graceful (USR2) añade pools sin cortar los ya servidos.
        //   - phpNN_fpm por versión: un reload NO crea el socket del pool nuevo -> restart (solo
        //     afecta a los dominios de esa versión).
        if ($changedDirs) {
            if (!class_exists('privilege')) {
                require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
            }
            $dirToVer = array_flip($installed);
            $actOn = function ($dir) use ($dirToVer) {
                $ver = $dirToVer[$dir] ?? '';
                $svc = self::serviceForVersion($ver);
                $action = ($ver === '') ? 'phpfpm_reload_svc' : 'phpfpm_restart_svc';
                try {
                    privilege::run($action, array($svc));
                } catch (Exception $e) {
                    if ($svc === 'php_fpm') { // fallback: SIGUSR2 directo al master del sistema
                        $pidFile = '/var/run/php-fpm.pid';
                        if (file_exists($pidFile) && function_exists('posix_kill')) {
                            $pid = (int)trim(file_get_contents($pidFile));
                            if ($pid > 0) posix_kill($pid, SIGUSR2);
                        }
                    }
                }
            };
            // Ronda 1: PERDEDORES (liberan el socket compartido). Ronda 2: GANADORES (lo crean al final).
            foreach (array_keys($lostDirs)   as $dir) { $actOn($dir); }
            foreach (array_keys($gainedDirs) as $dir) { $actOn($dir); }
        }

        return $totalActive;
    }

    /**
     * Comprueba si un usuario de sistema existe leyendo /etc/passwd.
     * No usa exec() — lectura directa de fichero.
     */
    private static function sysuserExists(string $sysuser): bool
    {
        $handle = @fopen('/etc/passwd', 'r');
        if (!$handle) return false;
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, $sysuser . ':') === 0) {
                fclose($handle);
                return true;
            }
        }
        fclose($handle);
        return false;
    }

    /**
     * Cambia recursivamente el dueño del directorio de un dominio a sysuser:www.
     * Solo se llama cuando el daemon corre como root.
     */
    private static function chownDomainDir(string $dir, string $sysuser): void
    {
        // Aislamiento entre inquilinos: propietario = h_USERNAME, grupo = www.
        //  - Directorios 02750 (setgid + rwxr-x---): Apache (grupo www) puede atravesar y
        //    servir estáticos; NINGÚN otro inquilino (fuera del grupo www) puede entrar.
        //    El bit setgid hace que los ficheros que cree el worker FPM del usuario hereden
        //    el grupo www, para que Apache pueda servirlos.
        //  - Ficheros 0640 (rw-r-----): dueño escribe, grupo www (Apache) lee, resto nada.
        chown($dir, $sysuser);
        chgrp($dir, 'www');
        @chmod($dir, 02750);
        $items = @scandir($dir);
        if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_link($path)) continue;
            chown($path, $sysuser);
            chgrp($path, 'www');
            if (is_dir($path)) {
                @chmod($path, 02750);
                self::chownDomainDir($path, $sysuser);
            } else {
                @chmod($path, 0640);
            }
        }
    }

    /**
     * Convierte una cadena de tamaño PHP ('128M', '2G', '512K') a bytes.
     */
    private static function parseSize(string $s): int
    {
        $s    = trim($s);
        $unit = strtolower(substr($s, -1));
        $val  = (int)$s;
        switch ($unit) {
            case 'g': return $val * 1073741824;
            case 'm': return $val * 1048576;
            case 'k': return $val * 1024;
            default:  return $val;
        }
    }

    /**
     * Devuelve el menor de dos valores de tamaño PHP.
     * Si domain_val <= pkg_val devuelve domain_val (personalización del admin permitida).
     * Si domain_val > pkg_val devuelve pkg_val (cap del paquete).
     */
    private static function capSize(string $domain_val, string $pkg_val): string
    {
        if (self::parseSize($domain_val) <= self::parseSize($pkg_val)) {
            return $domain_val;
        }
        return $pkg_val;
    }

    /**
     * Retorna la ruta del socket Unix para un directorio de vhost.
     */
    static function GetSocketPath($vh_directory_vc)
    {
        $name = self::PREFIX . preg_replace('/[^a-zA-Z0-9_]/', '_', $vh_directory_vc);
        return self::SOCKET_DIR . $name . '.sock';
    }

    /**
     * Inserta filas por defecto en x_domain_php para vhosts sin configuración.
     * Idempotente: usa INSERT IGNORE.
     */
    static function InsertDefaults()
    {
        global $zdbh;
        try {
            $zdbh->exec("
                INSERT IGNORE INTO x_domain_php (dp_vhost_fk)
                SELECT vh_id_pk FROM x_vhosts
                WHERE vh_deleted_ts IS NULL AND vh_type_in IN (1, 2)
            ");
        } catch (PDOException $e) {
            // Tabla puede no existir aún
        }
    }
}

}
