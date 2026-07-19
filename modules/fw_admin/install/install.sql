-- fw_admin v102 — Esquema de base de datos completo
-- Ejecutar como root de MariaDB:
--   mysql -u root -pPASS -h 127.0.0.1 bulwark_core < install.sql

-- IPs bloqueadas manualmente por el administrador (IPv4, IPv6, CIDR)
CREATE TABLE IF NOT EXISTS x_fw_blocked (
    fb_id_pk      INT AUTO_INCREMENT PRIMARY KEY,
    fb_ip_vc      VARCHAR(49)  NOT NULL,
    fb_reason_vc  VARCHAR(255) NOT NULL DEFAULT '',
    fb_added_by   INT          NOT NULL DEFAULT 0,
    fb_added_ts   INT          NOT NULL DEFAULT 0,
    fb_active_in  TINYINT      NOT NULL DEFAULT 1,
    fb_deleted_ts INT               DEFAULT NULL,
    UNIQUE KEY uk_ip (fb_ip_vc, fb_deleted_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IPs en lista blanca: nunca se bloquean (ni por pf ni por SSHGuard)
CREATE TABLE IF NOT EXISTS x_fw_whitelist (
    fw_id_pk      INT AUTO_INCREMENT PRIMARY KEY,
    fw_ip_vc      VARCHAR(49)  NOT NULL,
    fw_reason_vc  VARCHAR(255) NOT NULL DEFAULT '',
    fw_added_by   INT          NOT NULL DEFAULT 0,
    fw_added_ts   INT          NOT NULL DEFAULT 0,
    fw_deleted_ts INT               DEFAULT NULL,
    UNIQUE KEY uk_ip (fw_ip_vc, fw_deleted_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bans automáticos de SSHGuard (sincronizados por OnDaemonRun.hook.php)
CREATE TABLE IF NOT EXISTS x_fw_auto_banned (
    fa_id_pk      INT AUTO_INCREMENT PRIMARY KEY,
    fa_ip_vc      VARCHAR(49)  NOT NULL,
    fa_jail_vc    VARCHAR(64)  NOT NULL DEFAULT 'sshguard',
    fa_service_vc VARCHAR(32)  NOT NULL DEFAULT '',
    fa_port_in    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fa_since_ts   INT          NOT NULL DEFAULT 0,
    fa_active_in  TINYINT      NOT NULL DEFAULT 1,
    UNIQUE KEY uk_ip (fa_ip_vc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reglas personalizadas de pf (cargadas en anchor "bulwark_rules")
-- La política base de pf.conf debe ser: block in all / pass out all
CREATE TABLE IF NOT EXISTS x_fw_rules (
    fr_id_pk        INT AUTO_INCREMENT PRIMARY KEY,
    fr_action_en    ENUM('block','pass')      NOT NULL DEFAULT 'block',
    fr_proto_vc     VARCHAR(10)               NOT NULL DEFAULT 'tcp',
    fr_direction_en ENUM('in','out','any')    NOT NULL DEFAULT 'in',
    fr_src_vc       VARCHAR(49)               NOT NULL DEFAULT 'any',
    fr_port_in      SMALLINT UNSIGNED         NOT NULL DEFAULT 0,
    fr_port_max_in  SMALLINT UNSIGNED         NOT NULL DEFAULT 0,
    fr_desc_vc      VARCHAR(255)              NOT NULL DEFAULT '',
    fr_order_in     SMALLINT UNSIGNED         NOT NULL DEFAULT 100,
    fr_enabled_in   TINYINT                   NOT NULL DEFAULT 1,
    fr_added_ts     INT                       NOT NULL DEFAULT 0,
    UNIQUE KEY uk_rule (fr_proto_vc, fr_direction_en, fr_src_vc, fr_port_in, fr_port_max_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Claves de configuración en x_settings
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_pf_enabled',       'PF Enabled',       '1',
            'Habilitar gestión de cortafuegos pf', 'fw_admin');
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_sshguard_enabled', 'SSHGuard Enabled', '1',
            'Habilitar integración con SSHGuard', 'fw_admin');
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_ban_time',         'Ban Time',         '3600',
            'Tiempo de ban SSHGuard en segundos (BLOCK_TIME)', 'fw_admin');
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_max_retry',        'Max Retry',        '5',
            'Intentos antes del ban (THRESHOLD en sshguard.conf)', 'fw_admin');
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_find_time',        'Find Time',        '600',
            'Ventana de detección en segundos (DETECTION_TIME)', 'fw_admin');
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_status_json_path', 'Status JSON Path', '/var/bulwark/logs/fw_status.json',
            'Ruta del JSON de estado del cortafuegos', 'fw_admin');

-- Registrar módulo (categoría 2 = Server Admin) y asignar al grupo Administradores
INSERT IGNORE INTO x_modules
    (mo_category_fk, mo_name_vc, mo_version_in, mo_folder_vc, mo_type_en, mo_desc_tx, mo_installed_ts, mo_enabled_en)
    VALUES (2, 'Firewall Admin', 102, 'fw_admin', 'user',
            'Gestión de cortafuegos pf y SSHGuard. Bloqueo de IPs, lista blanca, bans automáticos y reglas de puerto.',
            UNIX_TIMESTAMP(), 'true');

INSERT IGNORE INTO x_permissions (pe_group_fk, pe_module_fk)
    SELECT 1, mo_id_pk FROM x_modules WHERE mo_name_vc='Firewall Admin' LIMIT 1;

-- ────────────────────────────────────────────────────────────────────────────
-- Reglas por defecto — política de apertura mínima para servidor de hosting.
-- Requiere que /etc/pf.conf tenga:
--   block in all          ← bloquea todo lo entrante por defecto
--   pass out all          ← permite todo lo saliente
--   anchor "bulwark_rules" ← donde se cargan estas reglas
-- ────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO x_fw_rules
    (fr_action_en, fr_proto_vc, fr_direction_en, fr_src_vc,
     fr_port_in, fr_port_max_in, fr_desc_vc, fr_order_in, fr_enabled_in, fr_added_ts)
VALUES
-- Administración
  ('pass', 'tcp',  'in', 'any', 22,    0,     'SSH (administración)',        10,  1, UNIX_TIMESTAMP()),
-- Web
  ('pass', 'tcp',  'in', 'any', 80,    0,     'HTTP',                        20,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 443,   0,     'HTTPS',                       30,  1, UNIX_TIMESTAMP()),
-- DNS
  ('pass', 'tcp',  'in', 'any', 53,    0,     'DNS (TCP)',                   40,  1, UNIX_TIMESTAMP()),
  ('pass', 'udp',  'in', 'any', 53,    0,     'DNS (UDP)',                   50,  1, UNIX_TIMESTAMP()),
-- FTP
  ('pass', 'tcp',  'in', 'any', 21,    0,     'FTP control',                 60,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 49152, 65534, 'FTP pasivo (rango datos)',    70,  1, UNIX_TIMESTAMP()),
-- Email SMTP
  ('pass', 'tcp',  'in', 'any', 25,    0,     'SMTP',                        80,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 587,   0,     'SMTP submission',             90,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 465,   0,     'SMTPS',                      100,  1, UNIX_TIMESTAMP()),
-- Email IMAP
  ('pass', 'tcp',  'in', 'any', 143,   0,     'IMAP',                       110,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 993,   0,     'IMAPS',                      120,  1, UNIX_TIMESTAMP()),
-- Email POP3
  ('pass', 'tcp',  'in', 'any', 110,   0,     'POP3',                       130,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 995,   0,     'POP3S',                      140,  1, UNIX_TIMESTAMP()),
-- ICMP y NTP
  ('pass', 'icmp', 'in', 'any', 0,     0,     'ICMP (ping/diagnóstico)',     150,  1, UNIX_TIMESTAMP()),
  ('pass', 'udp',  'in', 'any', 123,   0,     'NTP (sincronización hora)',   160,  1, UNIX_TIMESTAMP());
