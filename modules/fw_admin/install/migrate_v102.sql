-- fw_admin v102 — Migración: rango de puertos + reglas por defecto + política default-deny
-- Ejecutar como root de MariaDB:
--   mysql -u root -pFCzOZMkpm2ZHu2US -h 127.0.0.1 bulwark_core < migrate_v102.sql

-- Añadir columna para puerto máximo de rango (0 = puerto único)
ALTER TABLE x_fw_rules
    ADD COLUMN IF NOT EXISTS fr_port_max_in SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER fr_port_in;

-- Clave única para evitar duplicar reglas por defecto en reinstalaciones
ALTER TABLE x_fw_rules
    ADD UNIQUE KEY IF NOT EXISTS uk_rule (fr_proto_vc, fr_direction_en, fr_src_vc, fr_port_in, fr_port_max_in);

-- ────────────────────────────────────────────────────────────────────────────
-- Reglas por defecto: política de apertura mínima necesaria para un servidor
-- de hosting web/email/FTP/DNS.
-- La política base de pf.conf debe ser:
--   block in all        (bloquear todo lo entrante)
--   pass out all        (permitir todo lo saliente)
-- Estas reglas permiten explícitamente los puertos necesarios.
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
  ('pass', 'tcp',  'in', 'any', 49152, 65534, 'FTP pasivo (datos)',          70,  1, UNIX_TIMESTAMP()),
-- Email — SMTP
  ('pass', 'tcp',  'in', 'any', 25,    0,     'SMTP',                        80,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 587,   0,     'SMTP submission',             90,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 465,   0,     'SMTPS',                      100,  1, UNIX_TIMESTAMP()),
-- Email — IMAP
  ('pass', 'tcp',  'in', 'any', 143,   0,     'IMAP',                       110,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 993,   0,     'IMAPS',                      120,  1, UNIX_TIMESTAMP()),
-- Email — POP3
  ('pass', 'tcp',  'in', 'any', 110,   0,     'POP3',                       130,  1, UNIX_TIMESTAMP()),
  ('pass', 'tcp',  'in', 'any', 995,   0,     'POP3S',                      140,  1, UNIX_TIMESTAMP()),
-- ICMP y NTP
  ('pass', 'icmp', 'in', 'any', 0,     0,     'ICMP (ping/diagnóstico)',     150,  1, UNIX_TIMESTAMP()),
  ('pass', 'udp',  'in', 'any', 123,   0,     'NTP (sincronización hora)',   160,  1, UNIX_TIMESTAMP());

-- Actualizar versión del módulo
UPDATE x_modules SET mo_version_in=102 WHERE mo_folder_vc='fw_admin';
