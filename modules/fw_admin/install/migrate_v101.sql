-- fw_admin v101 — Migración: servicio/puerto en bans SSHGuard + reglas personalizadas pf
-- Ejecutar como root de MariaDB:
--   mysql -u root -pFCzOZMkpm2ZHu2US bulwark_core < migrate_v101.sql

-- Añadir columnas de servicio y puerto a x_fw_auto_banned
ALTER TABLE x_fw_auto_banned
    ADD COLUMN IF NOT EXISTS fa_service_vc VARCHAR(32) NOT NULL DEFAULT '' AFTER fa_jail_vc,
    ADD COLUMN IF NOT EXISTS fa_port_in    SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER fa_service_vc;

-- Tabla de reglas personalizadas de pf (gestionadas desde el panel)
CREATE TABLE IF NOT EXISTS x_fw_rules (
    fr_id_pk        INT AUTO_INCREMENT PRIMARY KEY,
    fr_action_en    ENUM('block','pass')        NOT NULL DEFAULT 'block',
    fr_proto_vc     VARCHAR(10)                  NOT NULL DEFAULT 'tcp',
    fr_direction_en ENUM('in','out','any')       NOT NULL DEFAULT 'in',
    fr_src_vc       VARCHAR(49)                  NOT NULL DEFAULT 'any',
    fr_port_in      SMALLINT UNSIGNED            NOT NULL DEFAULT 0,
    fr_desc_vc      VARCHAR(255)                 NOT NULL DEFAULT '',
    fr_order_in     SMALLINT UNSIGNED            NOT NULL DEFAULT 100,
    fr_enabled_in   TINYINT                      NOT NULL DEFAULT 1,
    fr_added_ts     INT                          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Actualizar versión del módulo
UPDATE x_modules SET mo_version_in=101 WHERE mo_folder_vc='fw_admin';
