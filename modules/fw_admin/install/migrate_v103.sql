-- fw_admin v103 — Protección brute force del panel web
-- Ejecutar como root de MariaDB:
--   mysql -u root -pFCzOZMkpm2ZHu2US -h 127.0.0.1 bulwark_core < migrate_v103.sql

-- Tabla de intentos de login fallidos (auto-bloqueada por PHP en init.inc.php)
CREATE TABLE IF NOT EXISTS x_fw_login_attempts (
    la_id_pk   INT AUTO_INCREMENT PRIMARY KEY,
    la_ip_vc   VARCHAR(49)  NOT NULL,
    la_user_vc VARCHAR(64)  NOT NULL DEFAULT '',
    la_ts_in   INT          NOT NULL DEFAULT 0,
    INDEX idx_ip_ts (la_ip_vc, la_ts_in),
    INDEX idx_ts    (la_ts_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nº máximo de intentos fallidos antes del auto-bloqueo
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_login_max',    'Login Max Attempts', '5',
            'Intentos de login fallidos antes de bloquear la IP', 'fw_admin');

-- Ventana de tiempo en segundos para contar los intentos (por defecto 10 min)
INSERT IGNORE INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc)
    VALUES ('fw_login_window', 'Login Window (s)',   '600',
            'Ventana de tiempo en segundos para contar intentos fallidos', 'fw_admin');

UPDATE x_modules SET mo_version_in=103 WHERE mo_folder_vc='fw_admin';
