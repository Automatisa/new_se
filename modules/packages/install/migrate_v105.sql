-- FIX-72: Límites PHP por paquete
-- Añade columnas de configuración PHP máxima a x_quotas.
-- Valores de paquete Admin (id=1): sin límite práctico.
-- Paquetes nuevos usan los DEFAULT de columna.

ALTER TABLE x_quotas
    ADD COLUMN IF NOT EXISTS qt_php_memory_vc  VARCHAR(10) DEFAULT '128M' AFTER qt_cronjobs_in,
    ADD COLUMN IF NOT EXISTS qt_php_upload_vc  VARCHAR(10) DEFAULT '50M'  AFTER qt_php_memory_vc,
    ADD COLUMN IF NOT EXISTS qt_php_post_vc    VARCHAR(10) DEFAULT '50M'  AFTER qt_php_upload_vc,
    ADD COLUMN IF NOT EXISTS qt_php_exec_in    INT(5)      DEFAULT 30     AFTER qt_php_post_vc,
    ADD COLUMN IF NOT EXISTS qt_php_maxinput_in INT(5)     DEFAULT 60     AFTER qt_php_exec_in;

-- Paquete Administration (id=1): sin restricción (2G / 1G / 1G / 300s / 600s)
UPDATE x_quotas SET
    qt_php_memory_vc   = '2G',
    qt_php_upload_vc   = '1G',
    qt_php_post_vc     = '1G',
    qt_php_exec_in     = 300,
    qt_php_maxinput_in = 600
WHERE qt_package_fk = 1;

-- Paquete Demo (id=2): valores conservadores
UPDATE x_quotas SET
    qt_php_memory_vc   = '128M',
    qt_php_upload_vc   = '50M',
    qt_php_post_vc     = '50M',
    qt_php_exec_in     = 30,
    qt_php_maxinput_in = 60
WHERE qt_package_fk = 2;
