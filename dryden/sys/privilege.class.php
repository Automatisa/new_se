<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/)
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 *
 * Privilege-escalation helper — replaces the legacy `zsudo` setuid binary.
 *
 * Background (security fix, June 2026): Bulwark historically used a custom
 * `zsudo` setuid-root binary (bulwark-installers/preconf/bin/zsudo.c) to
 * escalate from the web server user (`apache`/`www`) to root for a handful
 * of well-defined operations (restart Apache, reload BIND, reload cron,
 * chmod the BIND log, etc.). The wrapper's `EscapeArgs()` only stripped
 * single quotes; backticks, `$()` and `$(cmd)` were not filtered, so any
 * user able to influence the arguments passed to the wrapper (cron
 * `inTiming` was the most realistic vector) gained root command execution.
 *
 * The fix:
 *   - Eliminate the setuid binary entirely.
 *   - Replace each invocation site with a fixed whitelist table mapping an
 *     internal "action" key to a fully-formed command line (no string
 *     concatenation from runtime variables).
 *   - Hand off to `sudo` on Linux (rules written to
 *     `/etc/sudoers.d/bulwark`) or to `doas` on FreeBSD (rules written to
 *     `/usr/local/etc/doas.conf`). Either way the wrapper itself enforces
 *     a fixed command list with exact-argument matching, so even if a
 *     caller passed a malicious argument, no shell interpolation occurs.
 *
 * The installer is responsible for generating the corresponding rules
 * file based on the action table below; see
 * bulwark-installers/bulwark_install.sh. The class exposes:
 *   - `sudoersRules($webUser)` for the Linux/sudo branch.
 *   - `doasRules($webUser)`    for the FreeBSD/doas branch.
 *   - `wrapperChoice()`        so the installer can pick the right branch
 *                              without duplicating the OS-detection logic.
 *
 * NOTE — argument policy: arguments supplied by callers are limited to
 * values that come from the panel's own configuration (`ctrl_options`)
 * or, where unavoidable (e.g. the BIND log path), pass through
 * `fs_director::RemoveDoubleSlash` + a basename-only whitelist. Anything
 * else is rejected at the boundary.
 */
if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

class privilege
{
    /**
     * Master whitelist of privileged actions.
     *
     * Each key is a symbolic action name used in PHP call sites. The value
     * is the exact argv array that will be execve()'d through the wrapper
     * (sudo on Linux, doas on FreeBSD). The array is fixed at class load
     * time — no caller-supplied data flows in here; arguments from callers
     * are matched against placeholders below.
     *
     * Why this is safe: sudo and doas (with `args`) both enforce an
     * exact-match rule on the wrapped command. The PHP side also builds
     * the argv with no shell interpolation, so even if a caller passes a
     * malicious string for one of the fixed arguments (e.g. the BIND log
     * path), it cannot break out of the argv.
     *
     * `sudo_rule` is a one-line `User Host=(Runas) Options: command` snippet
     * for /etc/sudoers.d/bulwark.
     *
     * `doas_rule` is the trailing part of a doas.conf line — i.e. the
     * `identity [as target] cmd command [args ...]` portion, starting with
     * `cmd …`. The caller prepends `permit nopass` and the identity.
     *
     * For `bind_log_chmod`/`bind_log_chown` the doas rule pins the EXACT args
     * (`args 0664 /var/bulwark/logs/bind/bind.log`). This is critical: a bare
     * `cmd /bin/chmod` (no args) would let www run `doas chmod 4755 /bin/sh`
     * or `doas chown` on any file = trivial root escalation. Since the log
     * path is a fixed constant, pinning the args closes that hole entirely.
     *
     * @var array<string, array{argv?: array<int,string>, argv_template?: array<int,string>, sudo_rule: string, doas_rule: string}>
     */
    private static $actions = array(
        // Apache graceful reload — workers finalizan su petición en curso antes de reiniciarse.
        // Nunca corta conexiones activas. Usado por apache_admin y sencrypt tras renovar certs.
        'apache_reload' => array(
            'argv' => array('/usr/sbin/service', 'apache24', 'reload'),
            'sudo_rule' => '/usr/sbin/service apache24 reload',
            'doas_rule' => 'cmd /usr/sbin/service args apache24 reload',
        ),

        // BIND start/stop/reload (used by dns_admin + dns_manager).
        'bind_start' => array(
            'argv' => array('/usr/sbin/service', 'named', 'start'),
            'sudo_rule' => '/usr/sbin/service named start',
            'doas_rule' => 'cmd /usr/sbin/service args named start',
        ),
        'bind_stop' => array(
            'argv' => array('/usr/sbin/service', 'named', 'stop'),
            'sudo_rule' => '/usr/sbin/service named stop',
            'doas_rule' => 'cmd /usr/sbin/service args named stop',
        ),
        'bind_reload' => array(
            'argv' => array('/usr/sbin/service', 'named', 'reload'),
            'sudo_rule' => '/usr/sbin/service named reload',
            'doas_rule' => 'cmd /usr/sbin/service args named reload',
        ),

        // Reconstrucción SÍNCRONA de zonas DNS + reload de BIND (reutiliza el hook de dns_manager).
        // La usa el reto DNS-01 de Let's Encrypt para provisionar/limpiar el TXT _acme-challenge al
        // momento (sin esperar al ciclo del daemon). Sin args variables -> sin superficie de inyección.
        'dns_rebuild' => array(
            'argv' => array('/usr/local/bin/php', '-q', '/usr/local/bulwark/bin/dns_rebuild.php'),
            'sudo_rule' => '/usr/local/bin/php -q /usr/local/bulwark/bin/dns_rebuild.php',
            'doas_rule' => 'cmd /usr/local/bin/php args -q /usr/local/bulwark/bin/dns_rebuild.php',
        ),

        // Cluster DNS: firmar el CSR de un nodo que se une (inscripción por CSR). La API (usuario
        // bulwark) NO puede leer la clave de la CA (600 root); delega la firma en este wrapper root.
        // doas command-only: el firmador (dns_cluster_ca.sh sign-csr) re-valida el CSR, comprueba que
        // la IP es un nodo REGISTRADO en x_dns_nodes e IMPONE el SAN=IP+FQDN (no confía en el del CSR),
        // así que bulwark no puede obtener un cert para una IP ajena. __CSR_FILE__ acotado al spool.
        'cluster_sign_csr' => array(
            'argv_template' => array('/usr/local/bulwark/bin/dns_cluster_ca.sh', 'sign-csr', '__CSR_FILE__', '__IP_ADDR__'),
            'sudo_rule' => '/usr/local/bulwark/bin/dns_cluster_ca.sh sign-csr',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/dns_cluster_ca.sh',
        ),

        // Cron reload (used by cron module after writing the crontab).
        // Note: the legacy `zsudo` invocation took 4 args
        // (cron_reload_command, _flag, _user, _path). With sudo/doas, only
        // the service wrapper is needed; `service cron reload` already
        // reloads every user's crontab including the panel-managed file.
        // FreeBSD cron NO soporta `service cron reload`. La crontab del panel se instala con
        // `crontab -u www <staging>` (el staging lo escribe el panel en /var/bulwark/cron/www.cron,
        // www-writable; crontab lo coloca en /var/cron/tabs/www y avisa a cron). Sin argumentos.
        'cron_install' => array(
            'argv' => array('/usr/local/bulwark/bin/cron_install.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/cron_install.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/cron_install.sh',
        ),

        // chmod the BIND log file (was `chmod 0777 <bindlog>` in dns_admin).
        // 0664: owner (bind) rw, group (www) rw, others r. Lets www write via
        // group membership after bind_log_chown sets the group to www.
        'bind_log_chmod' => array(
            // The actual log path is filled in at call time from the
            // validated option, not from this fixed table.
            'argv_template' => array('/bin/chmod', '0664', '__BIND_LOG__'),
            'sudo_rule' => '/bin/chmod 0664 /var/bulwark/logs/bind/bind.log',
            // doas.conf: command-only (no `args` clause), since the BIND
            // log path is dynamic. The argument whitelist is enforced on
            // the PHP side via realpath + basename regex.
            'doas_rule' => 'cmd /bin/chmod args 0664 /var/bulwark/logs/bind/bind.log',
        ),

        // chown the BIND log file so the group becomes `www`, allowing the
        // web user to write (combined with 0664 from bind_log_chmod above).
        // bind:www 0664 means: bind daemon writes as owner, www writes as group.
        'bind_log_chown' => array(
            'argv_template' => array('/usr/sbin/chown', 'bind:www', '__BIND_LOG__'),
            'sudo_rule' => '/usr/sbin/chown bind:www /var/bulwark/logs/bind/bind.log',
            'doas_rule' => 'cmd /usr/sbin/chown args bind:www /var/bulwark/logs/bind/bind.log',
        ),

        // OpenDKIM reload (used by dns_manager daemon hook after writing KeyTable/SigningTable).
        // FreeBSD package installs the service as milter-opendkim (not opendkim).
        'dkim_reload' => array(
            'argv' => array('/usr/sbin/service', 'milter-opendkim', 'reload'),
            'sudo_rule' => '/usr/sbin/service milter-opendkim reload',
            'doas_rule' => 'cmd /usr/sbin/service args milter-opendkim reload',
        ),

        // ProFTPD reload (used by ftp_management after writing ftp-access.conf).
        'proftpd_reload' => array(
            'argv' => array('/usr/sbin/service', 'proftpd', 'reload'),
            'sudo_rule' => '/usr/sbin/service proftpd reload',
            'doas_rule' => 'cmd /usr/sbin/service args proftpd reload',
        ),

        // ProFTPD full restart (used by ftp_admin).
        'proftpd_restart' => array(
            'argv' => array('/usr/sbin/service', 'proftpd', 'restart'),
            'sudo_rule' => '/usr/sbin/service proftpd restart',
            'doas_rule' => 'cmd /usr/sbin/service args proftpd restart',
        ),

        // Generates a new self-signed TLS certificate for ProFTPD.
        'proftpd_cert_generate' => array(
            'argv' => array('/usr/local/bulwark/bin/ftp_cert_generate.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/ftp_cert_generate.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ftp_cert_generate.sh',
        ),

        // Validates and applies a new ProFTPD config written to /tmp/bulwark_proftpd_new.conf.
        'proftpd_config_update' => array(
            'argv' => array('/usr/local/bulwark/bin/ftp_config_update.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/ftp_config_update.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ftp_config_update.sh',
        ),

        // Updates TLSRSACertificateFile + TLSRSACertificateKeyFile in proftpd config.
        // Reads paths from /tmp/bulwark_ftp_cert and /tmp/bulwark_ftp_key.
        'proftpd_cert_paths_update' => array(
            'argv' => array('/usr/local/bulwark/bin/ftp_cert_paths_update.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/ftp_cert_paths_update.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ftp_cert_paths_update.sh',
        ),

        // Validates and installs an uploaded commercial SSL cert+key into the proftpd certs dir.
        // Reads from /tmp/bulwark_ftp_cert_upload and /tmp/bulwark_ftp_key_upload.
        'proftpd_cert_upload' => array(
            'argv' => array('/usr/local/bulwark/bin/ftp_cert_upload.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/ftp_cert_upload.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ftp_cert_upload.sh',
        ),

        // PHP-FPM graceful reload (used by API system reload endpoint).
        // 'service php_fpm reload' sends SIGUSR2: workers finish current requests
        // before restarting, so the API call that triggers this completes normally.
        // 'restart' (SIGTERM) would kill the current worker mid-response → 503.
        'phpfpm_reload' => array(
            'argv' => array('/usr/sbin/service', 'php_fpm', 'reload'),
            'sudo_rule' => '/usr/sbin/service php_fpm reload',
            'doas_rule' => 'cmd /usr/sbin/service args php_fpm reload',
        ),

        // Recarga graceful de UN master PHP-FPM concreto (multi-PHP): php_fpm (sistema) o phpNN_fpm
        // (versión con PREFIX propio). El servicio se valida contra ^(php_fpm|phpNN_fpm)$ y proviene
        // de la lista de versiones instaladas, no de entrada de usuario.
        // root_only: NUNCA se ejecuta vía doas (Regenerate corre siempre como root: en el daemon,
        // o vía el wrapper fpm_regenerate que ya es root). Por eso NO se emite regla en doas.conf —
        // así no se concede a www un 'service' comodín (que permitiría parar mysql, etc.).
        'phpfpm_reload_svc' => array(
            'argv_template' => array('/usr/sbin/service', '__PHPFPM_SVC__', 'reload'),
            'root_only' => true,
            'sudo_rule' => '',
            'doas_rule' => '',
        ),

        // Restart de UN master PHP-FPM concreto. Necesario para los FPM POR VERSIÓN (phpNN_fpm): a
        // diferencia del php_fpm del sistema, un reload (USR2) NO les crea el socket de un pool NUEVO;
        // solo el restart lo hace. Afecta únicamente a los dominios de esa versión. root_only.
        'phpfpm_restart_svc' => array(
            'argv_template' => array('/usr/sbin/service', '__PHPFPM_SVC__', 'restart'),
            'root_only' => true,
            'sudo_rule' => '',
            'doas_rule' => '',
        ),

        // Regenera todos los pools FPM desde x_domain_php y recarga FPM.
        // Usado al guardar config PHP de un dominio — aplica cambios sin esperar al daemon.
        // El script necesita root para escribir en /usr/local/etc/php-fpm.d/.
        'fpm_regenerate' => array(
            'argv' => array('/usr/local/bin/php', '-d', 'display_errors=Off',
                            '/usr/local/bulwark/bin/fpm_regen.php'),
            'sudo_rule' => '/usr/local/bin/php -d display_errors=Off /usr/local/bulwark/bin/fpm_regen.php',
            'doas_rule' => 'cmd /usr/local/bin/php args -d display_errors=Off /usr/local/bulwark/bin/fpm_regen.php',
        ),

        // ---- fw_admin: cortafuegos pf + SSHGuard --------------------------------
        //
        // Argumentos dinámicos (IPs) se pasan a través de archivos temporales con
        // permisos root:bulwark 660, nunca directamente como argv. Los scripts wrapper
        // en /usr/local/bulwark/bin/ validan el contenido con regex antes de usarlo.

        // Reconstruye tabla pf 'bulwark_blocked' desde x_fw_blocked de la BD.
        'fw_block_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_block_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_block_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_block_apply.sh',
        ),

        // Reconstruye tabla pf 'bulwark_whitelist' desde x_fw_whitelist de la BD.
        'fw_whitelist_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_whitelist_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_whitelist_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_whitelist_apply.sh',
        ),

        // Desbanea la IP escrita en /var/bulwark/run/fw_unban_request (root:bulwark 660).
        // El script valida el contenido con regex antes de llamar a pfctl.
        'fw_sshguard_unban' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_sshguard_unban.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_sshguard_unban.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_sshguard_unban.sh',
        ),

        // Vuelca estado de pf + SSHGuard a /var/bulwark/logs/fw_status.json (www:www 640).
        'fw_status_dump' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_status_dump.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_status_dump.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_status_dump.sh',
        ),

        // Aplica reglas personalizadas de x_fw_rules al anchor pf "bulwark_rules".
        'fw_rules_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_rules_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_rules_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_rules_apply.sh',
        ),

        // Recarga el servicio pf (tras cambios manuales en pf.conf).
        'fw_pf_reload' => array(
            'argv'      => array('/usr/sbin/service', 'pf', 'reload'),
            'sudo_rule' => '/usr/sbin/service pf reload',
            'doas_rule' => 'cmd /usr/sbin/service args pf reload',
        ),

        // Reinicia SSHGuard (tras cambios en sshguard.conf).
        'fw_sshguard_restart' => array(
            'argv'      => array('/usr/sbin/service', 'sshguard', 'restart'),
            'sudo_rule' => '/usr/sbin/service sshguard restart',
            'doas_rule' => 'cmd /usr/sbin/service args sshguard restart',
        ),

        // Activa/desactiva pf o SSHGuard según /var/bulwark/run/fw_service_toggle_req
        // ("pf|sshguard on|off"). El script valida el contenido y hace service + sysrc.
        'fw_service_toggle' => array(
            'argv'      => array('/usr/local/bulwark/bin/fw_service_toggle.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/fw_service_toggle.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/fw_service_toggle.sh',
        ),

        // ---- hosting_users: usuarios de sistema por cuenta de hosting ----------
        //
        // El nombre de usuario a procesar se pasa mediante un fichero de petición
        // en /var/bulwark/run/ (root:bulwark 660), nunca como argumento directo.
        // El script valida el contenido con regex antes de actuar.

        // Crea el usuario de sistema h_USERNAME y corrige la propiedad de hostdata.
        'hosting_user_add' => array(
            'argv'      => array('/usr/local/bulwark/bin/hosting_user_add.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/hosting_user_add.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/hosting_user_add.sh',
        ),

        // Crea el esqueleto de directorios de un dominio/subdominio (web/<dir>/{public_html,tmp,
        // logs,_errorpages,_cgi-bin}) con ownership h_USERNAME:www y permisos de aislamiento. La
        // orden "USERNAME|VH_DIRECTORY" se pasa por /var/bulwark/run/vhost_diradd_req; el script
        // valida ambos campos (anti path-traversal). Necesario porque web/ es 2750 y www no puede
        // crear ahí -> sin esto los dominios quedaban sin public_html (Apache 403).
        'vhost_dir_add' => array(
            'argv'      => array('/usr/local/bulwark/bin/vhost_dir_add.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/vhost_dir_add.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/vhost_dir_add.sh',
        ),

        // Elimina el usuario de sistema h_USERNAME y su grupo.
        'hosting_user_del' => array(
            'argv'      => array('/usr/local/bulwark/bin/hosting_user_del.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/hosting_user_del.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/hosting_user_del.sh',
        ),

        // Restaura los FICHEROS del home de una cuenta desde un .zip de backup. La orden
        // ("USERNAME|/ruta/backup.zip") se pasa por /var/bulwark/run/account_restore_req;
        // el script valida el usuario y que el zip esté en backups/ o restore/ del propio home.
        'account_restore' => array(
            'argv'      => array('/usr/local/bulwark/bin/account_restore.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/account_restore.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/account_restore.sh',
        ),

        // Aplica cuotas de disco UFS por cuenta (uid h_USER). Orden ("USERNAME|HARD_KB" por
        // línea) en /var/bulwark/run/disk_quota_req; el script valida y llama a edquota.
        'disk_quota_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/disk_quota_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/disk_quota_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/disk_quota_apply.sh',
        ),

        // rspamd start / stop / restart. Usados por antispam_admin.
        'rspamd_start' => array(
            'argv'      => array('/usr/sbin/service', 'rspamd', 'start'),
            'sudo_rule' => '/usr/sbin/service rspamd start',
            'doas_rule' => 'cmd /usr/sbin/service args rspamd start',
        ),
        'rspamd_stop' => array(
            'argv'      => array('/usr/sbin/service', 'rspamd', 'stop'),
            'sudo_rule' => '/usr/sbin/service rspamd stop',
            'doas_rule' => 'cmd /usr/sbin/service args rspamd stop',
        ),
        'rspamd_restart' => array(
            'argv'      => array('/usr/sbin/service', 'rspamd', 'restart'),
            'sudo_rule' => '/usr/sbin/service rspamd restart',
            'doas_rule' => 'cmd /usr/sbin/service args rspamd restart',
        ),
        'rspamd_reload' => array(
            'argv'      => array('/usr/sbin/service', 'rspamd', 'reload'),
            'sudo_rule' => '/usr/sbin/service rspamd reload',
            'doas_rule' => 'cmd /usr/sbin/service args rspamd reload',
        ),
        'antispam_rbl_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/antispam_rbl_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/antispam_rbl_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/antispam_rbl_apply.sh',
        ),
        // Correo del sistema (root/postmaster) — usado por mail_admin. Sin args: el destino se
        // lee del ajuste system_mail_to en la BD.
        'sysmail_alias_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/sysmail_alias_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/sysmail_alias_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/sysmail_alias_apply.sh',
        ),
        // Actualizaciones del sistema — usadas por el módulo updates (solo admin).
        'sys_update_check' => array(
            'argv'      => array('/usr/local/bulwark/bin/sys_update_check.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/sys_update_check.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/sys_update_check.sh',
        ),
        'pkg_upgrade' => array(
            'argv'      => array('/usr/local/bulwark/bin/pkg_upgrade.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/pkg_upgrade.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/pkg_upgrade.sh',
        ),
        'freebsd_update_apply' => array(
            'argv'      => array('/usr/local/bulwark/bin/freebsd_update_apply.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/freebsd_update_apply.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/freebsd_update_apply.sh',
        ),
        'panel_update' => array(
            'argv'      => array('/usr/local/bulwark/bin/panel_update.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/panel_update.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/panel_update.sh',
        ),
        // Pinning de paquetes: salto de MAYOR de un paquete gestionado, confirmado por el admin.
        // El nombre del paquete es dinámico (__PKG_NAME__): se valida con regex en PHP y, sobre
        // todo, contra la whitelist MANAGED dentro de pkg_pin.sh (fuente única). doas 'command-only'
        // (sin args) porque el argumento es dinámico; la seguridad la impone el script.
        // check/auto-sub/lock-all NO necesitan regla: los llama el daemon/instalador ya como root,
        // y 'check' va dentro de sys_update_check.sh.
        'pkg_pin_verify' => array(
            'argv_template' => array('/usr/local/bulwark/bin/pkg_pin.sh', 'verify-major', '__PKG_NAME__'),
            'sudo_rule' => '/usr/local/bulwark/bin/pkg_pin.sh verify-major',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/pkg_pin.sh',
        ),
        // Multi-IP: añadir/quitar un alias de IP en la interfaz principal. La IP es dinámica
        // (__IP_ADDR__, validada en PHP como IPv4/IPv6); el script re-valida y nunca toca la primaria.
        'ip_alias_add' => array(
            'argv_template' => array('/usr/local/bulwark/bin/ip_alias.sh', 'add', '__IP_ADDR__'),
            'sudo_rule' => '/usr/local/bulwark/bin/ip_alias.sh add',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ip_alias.sh',
        ),
        'ip_alias_del' => array(
            'argv_template' => array('/usr/local/bulwark/bin/ip_alias.sh', 'del', '__IP_ADDR__'),
            'sudo_rule' => '/usr/local/bulwark/bin/ip_alias.sh del',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/ip_alias.sh',
        ),
        // Multi-IP Fase 3b: regenera los transportes de Postfix por IP dedicada (envío saliente).
        // Sin args; el script hace 'postfix check' antes de recargar (no rompe el correo).
        'mail_ip_sync' => array(
            'argv'      => array('/usr/local/bulwark/bin/mail_ip_transports.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/mail_ip_transports.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/mail_ip_transports.sh',
        ),

        // ClamAV — usados por clamav_admin
        'clamd_start' => array(
            'argv'      => array('/usr/sbin/service', 'clamav_clamd', 'start'),
            'sudo_rule' => '/usr/sbin/service clamav_clamd start',
            'doas_rule' => 'cmd /usr/sbin/service args clamav_clamd start',
        ),
        'clamd_stop' => array(
            'argv'      => array('/usr/sbin/service', 'clamav_clamd', 'stop'),
            'sudo_rule' => '/usr/sbin/service clamav_clamd stop',
            'doas_rule' => 'cmd /usr/sbin/service args clamav_clamd stop',
        ),
        'clamd_restart' => array(
            'argv'      => array('/usr/sbin/service', 'clamav_clamd', 'restart'),
            'sudo_rule' => '/usr/sbin/service clamav_clamd restart',
            'doas_rule' => 'cmd /usr/sbin/service args clamav_clamd restart',
        ),
        'freshclam_start' => array(
            'argv'      => array('/usr/sbin/service', 'clamav_freshclam', 'start'),
            'sudo_rule' => '/usr/sbin/service clamav_freshclam start',
            'doas_rule' => 'cmd /usr/sbin/service args clamav_freshclam start',
        ),
        'freshclam_restart' => array(
            'argv'      => array('/usr/sbin/service', 'clamav_freshclam', 'restart'),
            'sudo_rule' => '/usr/sbin/service clamav_freshclam restart',
            'doas_rule' => 'cmd /usr/sbin/service args clamav_freshclam restart',
        ),
        'freshclam_update' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_freshclam_update.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_freshclam_update.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_freshclam_update.sh',
        ),
        'clamd_scan_mailboxes' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_scan_mailboxes.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_scan_mailboxes.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_scan_mailboxes.sh',
        ),
        // Lanzadores daemon(8) — retornan inmediatamente sin bloquear PHP-FPM
        'clamd_scan_launch' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_scan_launch.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_scan_launch.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_scan_launch.sh',
        ),
        'freshclam_launch' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_freshclam_launch.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_freshclam_launch.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_freshclam_launch.sh',
        ),
        // Parar clamd EN 2º PLANO (daemon -f): 'service clamav_clamd stop' puede tardar/colgar, así
        // que se detacha para no bloquear la petición. argv fijo -> sin script wrapper (FIX-182).
        'clamd_stop_bg' => array(
            'argv'      => array('/usr/sbin/daemon', '-f', '/usr/sbin/service', 'clamav_clamd', 'stop'),
            'sudo_rule' => '/usr/sbin/daemon -f /usr/sbin/service clamav_clamd stop',
            'doas_rule' => 'cmd /usr/sbin/daemon args -f /usr/sbin/service clamav_clamd stop',
        ),
        'clamav_cron_update' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_cron_update.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_cron_update.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_cron_update.sh',
        ),
        // Restaura un archivo de cuarentena a /var/mail/.
        // El nombre del archivo se pasa en /var/bulwark/run/clamav_restore_request
        // (www:www 600), nunca como argumento directo, para evitar inyección de rutas.
        'clamd_quarantine_restore' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_quarantine_restore.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_quarantine_restore.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_quarantine_restore.sh',
        ),

        'clamav_user_scan' => array(
            'argv'      => array('/usr/local/bulwark/bin/clamav_user_scan.sh'),
            'sudo_rule' => '/usr/local/bulwark/bin/clamav_user_scan.sh',
            'doas_rule' => 'cmd /usr/local/bulwark/bin/clamav_user_scan.sh',
        ),

    );

    /**
     * Run a privileged action via sudo (or doas as a fallback).
     *
     * @param string $action One of the keys in self::$actions.
     * @param array  $args   Optional positional arguments for the (rare)
     *                       action that uses argv_template.
     * @return array{0:int,1:string,2:string} [exit_code, stdout, stderr]
     * @throws RuntimeException If the action is unknown or sudo/doas missing.
     */
    public static function run($action, array $args = array(), $nowait = false)
    {
        if (!isset(self::$actions[$action])) {
            throw new RuntimeException("privilege::run: unknown action '$action'");
        }

        $spec = self::$actions[$action];

        // Materialize argv: either the fixed list, or fill the template.
        if (isset($spec['argv'])) {
            $argv = $spec['argv'];
        } else {
            $argv = self::materializeTemplate($spec['argv_template'], $args);
        }

        // If already root, no wrapper needed — doas/sudo would reject root
        // because doas.conf only grants the web user (www), not root itself.
        if (self::isRoot()) {
            $fullArgv = $argv;
        } else {
            $wrapper = self::detectWrapper();
            if ($wrapper === null) {
                throw new RuntimeException(
                    'privilege::run: no sudo/doas binary available — refusing to fall back to setuid zsudo.'
                );
            }
            $fullArgv = array_merge(array($wrapper[0]), $wrapper[1], $argv);
        }

        if ($nowait) {
            // Para comandos que arrancan daemons: stdout/stderr a /dev/null para que
            // proc_open no bloquee esperando a que el proceso hijo cierre los descriptores.
            $descriptors = array(
                0 => array('pipe', 'r'),
                1 => array('file', '/dev/null', 'w'),
                2 => array('file', '/dev/null', 'w'),
            );
            $proc = proc_open($fullArgv, $descriptors, $pipes, null, null, array('bypass_shell' => true));
            if (!is_resource($proc)) {
                throw new RuntimeException('privilege::run: proc_open failed for ' . $fullArgv[0]);
            }
            fclose($pipes[0]);
            $exitCode = proc_close($proc);
            return array((int)$exitCode, '', '');
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = proc_open($fullArgv, $descriptors, $pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($proc)) {
            throw new RuntimeException('privilege::run: proc_open failed for ' . $fullArgv[0]);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return array((int)$exitCode, (string)$stdout, (string)$stderr);
    }

    /**
     * Return the sudoers rules that the installer must place under
     * /etc/sudoers.d/bulwark.
     *
     * @param string $webUser The user the web server runs as
     *                        (apache / www / www-data).
     * @return string Plain-text rules block, ready to write to disk.
     */
    public static function sudoersRules($webUser)
    {
        // Whitelist the user name too — same rule as for the action keys.
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $webUser)) {
            throw new RuntimeException("privilege::sudoersRules: invalid web user '$webUser'");
        }
        $lines = array(
            '# Bulwark privilege rules (security fix, June 2026).',
            '# Generated automatically — do not edit by hand; regenerate via',
            '#   php -r "require \"/usr/local/bulwark/panel/dryden/sys/privilege.class.php\"; echo privilege::sudoersRules(\"www\");\"',
            '# Each line below maps to a single action key in privilege::run().',
            '',
        );
        foreach (self::$actions as $key => $spec) {
            $lines[] = "# privilege::run('$key')";
            $lines[] = "$webUser ALL=(root) NOPASSWD: " . $spec['sudo_rule'];
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Return the doas.conf rules that the installer must place at
     * /usr/local/etc/doas.conf on FreeBSD.
     *
     * Format follows doas.conf(5):
     *     permit nopass <identity> as root <doas_rule>
     *
     * @param string $webUser The user the web server runs as
     *                        (apache / www / www-data).
     * @return string Plain-text rules block, ready to write to disk.
     */
    public static function doasRules($webUser)
    {
        // Whitelist the user name too — same rule as for the action keys.
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $webUser)) {
            throw new RuntimeException("privilege::doasRules: invalid web user '$webUser'");
        }
        $lines = array(
            '# Bulwark privilege rules (security fix, June 2026).',
            '# Generated automatically — do not edit by hand; regenerate via',
            '#   php -r "require \"/usr/local/bulwark/panel/dryden/sys/privilege.class.php\"; echo privilege::doasRules(\"www\");\"',
            '# Each line below maps to a single action key in privilege::run().',
            '# Lines take effect after installing to /usr/local/etc/doas.conf',
            '# with mode 0600 owner root:wheel.',
            '',
        );
        foreach (self::$actions as $key => $spec) {
            // Acciones root_only no se ejecutan por doas: no se les emite regla (no dar más a www).
            if (!empty($spec['root_only'])) {
                continue;
            }
            $lines[] = "# privilege::run('$key')";
            $lines[] = "permit nopass $webUser as root " . $spec['doas_rule'];
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Returns true when the current process is running as root (uid 0).
     * Avoids posix_getuid() which is not loaded on FreeBSD PHP-FPM.
     */
    private static function isRoot()
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid() === 0;
        }
        $out = array();
        @exec('id -u 2>/dev/null', $out);
        return isset($out[0]) && (int)$out[0] === 0;
    }

    /**
     * Decide which privilege-escalation wrapper applies on the current OS.
     *
     *   FreeBSD → doas  (sudo is not in base; security/doas is the idiom).
     *   Linux   → sudo  (the historical choice; works with doas fallback too).
     *
     * The installer calls this once, picks the matching rules generator
     * (sudoersRules / doasRules) and writes the result to the right path
     * (/etc/sudoers.d/bulwark vs /usr/local/etc/doas.conf).
     *
     * Override the choice by setting the BULWARK_PRIVILEGE_WRAPPER env var
     * to either "sudo" or "doas" (useful for Vagrant/port testing).
     *
     * @return string "sudo" or "doas"
     */
    public static function wrapperChoice()
    {
        $override = getenv('BULWARK_PRIVILEGE_WRAPPER');
        if ($override === 'sudo' || $override === 'doas') {
            return $override;
        }
        // PHP_OS returns "FreeBSD" on FreeBSD, "Linux" on Linux. Be lenient
        // and also accept the uname() form ("FreeBSD", "OpenBSD", …).
        $os = strtolower((string)php_uname('s'));
        if ($os === 'freebsd' || $os === 'openbsd' || $os === 'netbsd') {
            return 'doas';
        }
        return 'sudo';
    }

    /**
     * Detect sudo/doas on PATH. Returns array(argv0, argv) for proc_open,
     * or null if neither is available.
     *
     *   sudo: argv = array('--', ...) so the wrapped command cannot be
     *                     interpreted as a sudo option (defence in depth).
     *   doas: argv = array() — we just pass the command and its args
     *                     directly; doas does NOT have an option-sentinel
     *                     like sudo's `--`, but the command is fixed.
     *
     * The wrapper is chosen by wrapperChoice(); we then probe PATH for the
     * matching binary. If neither is available we throw at run time.
     *
     * @return array{0:string,1:array<int,string>}|null
     */
    private static function detectWrapper()
    {
        $choice = self::wrapperChoice();
        if ($choice === 'sudo') {
            foreach (array('/usr/local/bin/sudo', '/usr/bin/sudo') as $cand) {
                if (is_executable($cand)) {
                    return array($cand, array('--'));
                }
            }
            // Fall back to doas if sudo is missing on this Linux box.
            foreach (array('/usr/local/bin/doas', '/usr/bin/doas') as $cand) {
                if (is_executable($cand)) {
                    return array($cand, array());
                }
            }
        } else { // doas
            foreach (array('/usr/local/bin/doas', '/usr/bin/doas') as $cand) {
                if (is_executable($cand)) {
                    return array($cand, array());
                }
            }
            // Fall back to sudo on a FreeBSD box that has it installed.
            foreach (array('/usr/local/bin/sudo', '/usr/bin/sudo') as $cand) {
                if (is_executable($cand)) {
                    return array($cand, array('--'));
                }
            }
        }
        return null;
    }


    /**
     * Fill placeholders in an argv template with caller-supplied arguments,
     * after sanitising each value against a strict whitelist per slot.
     *
     * Currently only the `bind_log_chmod` action uses a template.
     *
     * @param array<int,string> $template
     * @param array<int,string> $args
     * @return array<int,string>
     */
    private static function materializeTemplate(array $template, array $args)
    {
        $out = array();
        $argIdx = 0;
        foreach ($template as $slot) {
            if (strpos($slot, '__') === 0 && substr($slot, -2) === '__') {
                if (!isset($args[$argIdx])) {
                    throw new RuntimeException("privilege::run: missing argument $argIdx for template");
                }
                $value = (string)$args[$argIdx];
                switch ($slot) {
                    case '__BIND_LOG__':
                        // Must be an absolute path under /var/bulwark,
                        // no shell metacharacters, no traversal.
                        $real = realpath($value);
                        $base = basename($real);
                        if ($real === false
                            || strpos($real, '/var/bulwark/logs/bind/') !== 0
                            || !preg_match('/^[A-Za-z0-9._\-]+$/', $base)
                            || $base === '' || $base === '.' || $base === '..'
                        ) {
                            throw new RuntimeException("privilege::run: invalid bind_log path '$value'");
                        }
                        $out[] = $real;
                        break;
                    case '__IP_ADDR__':
                        // IPv4 o IPv6 válida (filter_var). El script ip_alias.sh re-valida y sólo
                        // toca alias (nunca la primaria). Sin metacaracteres posibles tras esto.
                        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                            throw new RuntimeException("privilege::run: invalid IP '$value'");
                        }
                        $out[] = $value;
                        break;
                    case '__CSR_FILE__':
                        // Fichero CSR en el spool del cluster (/var/bulwark/run/csr/), basename seguro,
                        // sin traversal. El firmador (dns_cluster_ca.sh sign-csr) re-valida el CSR y la
                        // IP contra x_dns_nodes e IMPONE el SAN (no confía en el del CSR).
                        $real = realpath($value);
                        $base = ($real !== false) ? basename($real) : '';
                        if ($real === false
                            || strpos($real, '/var/bulwark/run/csr/') !== 0
                            || !preg_match('/^[A-Za-z0-9._\-]+\.csr$/', $base)
                        ) {
                            throw new RuntimeException("privilege::run: invalid CSR path '$value'");
                        }
                        $out[] = $real;
                        break;
                    case '__PHPFPM_SVC__':
                        // Servicio rc.d de PHP-FPM: el del sistema (php_fpm) o el de una versión
                        // con PREFIX propio (phpNN_fpm). La versión la elige el admin de una lista
                        // de versiones INSTALADAS (autodetectadas), no es texto libre; esto es el
                        // saneo de forma que impide cualquier otro servicio/metacaracter.
                        if (!preg_match('/^(php_fpm|php[0-9]{2}_fpm)$/', $value)) {
                            throw new RuntimeException("privilege::run: invalid php-fpm service '$value'");
                        }
                        $out[] = $value;
                        break;
                    case '__PKG_NAME__':
                        // Nombre de paquete pkg(8): letras/dígitos/._- ; sin metacaracteres ni
                        // rutas. La whitelist real (qué paquetes se pueden tocar) la impone
                        // pkg_pin.sh (MANAGED); esto es el saneo de forma.
                        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,39}$/', $value)) {
                            throw new RuntimeException("privilege::run: invalid pkg name '$value'");
                        }
                        $out[] = $value;
                        break;
                    default:
                        throw new RuntimeException("privilege::run: unknown template slot '$slot'");
                }
                $argIdx++;
            } else {
                $out[] = $slot;
            }
        }
        return $out;
    }
}
