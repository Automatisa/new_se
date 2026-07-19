-- 0001_recent_settings.sql — Asegura, en instalaciones EXISTENTES, los ajustes añadidos
-- recientemente a x_settings (que una instalación nueva ya recibe vía bulwark_core.sql).
-- Idempotente: patrón INSERT ... WHERE NOT EXISTS (no duplica ni falla si ya existen).

INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc, so_usereditable_en)
SELECT 'maillimit_per_hour', 'Mail limit per hour', '200', 'Limite duro de correos/hora por cuenta de hosting via sendmail_path (0=ilimitado)', 'Antispam', 'false'
WHERE NOT EXISTS (SELECT 1 FROM x_settings WHERE so_name_vc = 'maillimit_per_hour');

INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc, so_usereditable_en)
SELECT 'system_mail_to', 'System mail destination', '', 'Buzon al que se reenvia el correo del sistema (root/postmaster); vacio = entrega local en /var/mail/root', 'Mail Config', 'false'
WHERE NOT EXISTS (SELECT 1 FROM x_settings WHERE so_name_vc = 'system_mail_to');

INSERT INTO x_settings (so_name_vc, so_cleanname_vc, so_value_tx, so_desc_tx, so_module_vc, so_usereditable_en)
SELECT 'ratelimit_enabled', 'Ratelimit enabled', '1', 'Activar el rate-limit de salida (anti-spam)', 'Antispam', 'false'
WHERE NOT EXISTS (SELECT 1 FROM x_settings WHERE so_name_vc = 'ratelimit_enabled');
