<?php

/**
 * backup_retention — Límite de copias locales por paquete y control de cuota de disco.
 *
 * Lógica (analizada con el consumo de disco de la cuenta):
 *  - Las copias locales viven en home/backups/ → YA cuentan en el disco de la cuenta
 *    (bulwark_core_module suma todo el home). Por eso una copia puede empujar a la cuenta por
 *    encima de su cuota y hacer que apache_admin desactive sus dominios.
 *  - Esta clase impone (a) un MÁXIMO de copias locales por paquete (qt_backups_in; 0 =
 *    ilimitado) con rotación de las más antiguas, y (b) un chequeo de cuota para no crear
 *    una copia que deje la cuenta por encima del límite de disco.
 */
class sys_backup_retention
{
    /** Nº máximo de copias locales del paquete de la cuenta (0 = ilimitado). */
    public static function getMaxLocal($userid)
    {
        global $zdbh;
        $q = $zdbh->prepare(
            "SELECT q.qt_backups_in
               FROM x_accounts a
               JOIN x_quotas q ON q.qt_package_fk = a.ac_package_fk
              WHERE a.ac_id_pk = :u LIMIT 1"
        );
        $q->execute(array(':u' => (int)$userid));
        $v = $q->fetchColumn();
        return $v === false ? 0 : max(0, (int)$v);
    }

    /** Máximo de copias REMOTAS (FTP) del paquete (qt_backups_remote_in). 0 = ilimitado. */
    public static function getMaxRemote($userid)
    {
        global $zdbh;
        $q = $zdbh->prepare(
            "SELECT q.qt_backups_remote_in
               FROM x_accounts a
               JOIN x_quotas q ON q.qt_package_fk = a.ac_package_fk
              WHERE a.ac_id_pk = :u LIMIT 1"
        );
        $q->execute(array(':u' => (int)$userid));
        $v = $q->fetchColumn();
        return $v === false ? 0 : max(0, (int)$v);
    }

    /** Lista los .zip de home/backups/ ordenados por fecha (más antiguo primero). */
    public static function listLocal($username)
    {
        $dir = ctrl_options::GetSystemOption('hosted_dir') . $username . '/backups/';
        $out = array();
        if (is_dir($dir) && ($h = @opendir($dir))) {
            while (false !== ($f = readdir($h))) {
                if (substr($f, -4) === '.zip' && is_file($dir . $f)) {
                    $out[$dir . $f] = @filemtime($dir . $f);
                }
            }
            closedir($h);
        }
        asort($out); // por mtime ascendente
        return array_keys($out);
    }

    /**
     * Rota las copias locales para que no haya más de $slots (por defecto el máximo del
     * paquete). Borra las más antiguas. Devuelve el nº de copias borradas.
     */
    public static function enforceLocal($username, $userid, $slots = null)
    {
        $max = ($slots === null) ? self::getMaxLocal($userid) : (int)$slots;
        if ($max <= 0) return 0; // 0 = ilimitado
        $files = self::listLocal($username);
        $deleted = 0;
        while (count($files) > $max) {
            $old = array_shift($files);
            if (@unlink($old)) $deleted++;
        }
        return $deleted;
    }

    /** Límite de disco de la cuenta en bytes (0 = ilimitado). Consulta directa a la BD para
     *  no depender de ctrl_users (dobackup.php es un endpoint suelto que no lo carga). */
    public static function diskQuotaBytes($userid)
    {
        global $zdbh;
        $q = $zdbh->prepare("SELECT q.qt_diskspace_bi
                               FROM x_accounts a JOIN x_quotas q ON q.qt_package_fk = a.ac_package_fk
                              WHERE a.ac_id_pk = :u LIMIT 1");
        $q->execute(array(':u' => (int)$userid));
        $v = $q->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    /** Uso de disco actual del home en bytes (medición real, no el valor cacheado). */
    public static function diskUsedBytes($username)
    {
        $home = ctrl_options::GetSystemOption('hosted_dir') . $username;
        return is_dir($home) ? (int)fs_director::GetDirectorySize($home) : 0;
    }

    /**
     * ¿Añadir un fichero de $addBytes dejaría la cuenta por encima de su cuota de disco?
     * (false si la cuota es 0/ilimitada). Mide el home real para ser exacto.
     */
    public static function wouldExceedQuota($username, $userid, $addBytes)
    {
        $quota = self::diskQuotaBytes($userid);
        if ($quota <= 0) return false;
        return (self::diskUsedBytes($username) + (int)$addBytes) > $quota;
    }

    /**
     * GUARD DE DISCO DEL SERVIDOR: ¿hay espacio suficiente en $temp_dir (carpeta compartida donde
     * se genera el .zip temporal, que NO cuenta para la cuota del usuario) para hacer una copia de
     * esta cuenta sin dejar el disco por debajo del suelo de seguridad? Sin esto, muchas copias
     * simultáneas de cuentas grandes llenarían el HD del servidor. El tamaño se estima por el uso
     * de disco del home. Suelo configurable con la opción 'backup_disk_floor_mb' (por defecto 1 GB).
     * Devuelve true si SE PUEDE proceder.
     */
    public static function tempSpaceGuard($username, $temp_dir)
    {
        $free = @disk_free_space($temp_dir);
        if ($free === false) return true;   // no medible -> no bloquear
        $needed  = self::diskUsedBytes($username);
        $floorMb = ctrl_options::GetSystemOption('backup_disk_floor_mb');
        $floor   = (is_numeric($floorMb) && (int)$floorMb > 0) ? (int)$floorMb * 1048576 : 1073741824;
        return ($free - $needed) >= $floor;
    }
}
