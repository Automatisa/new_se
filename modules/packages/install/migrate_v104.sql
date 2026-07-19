-- FIX-68: añade cuota de cron jobs por paquete
-- Aplicar en instalaciones existentes: mysql bulwark < migrate_v104.sql
ALTER TABLE x_quotas
    ADD COLUMN qt_cronjobs_in int(6) DEFAULT '0' AFTER qt_mysql_in;

-- Actualizar todos los paquetes existentes a 0 (sin crons) por defecto.
-- El admin debe editar cada paquete y asignar el valor deseado (-1 = ilimitado).
UPDATE x_quotas SET qt_cronjobs_in = 0 WHERE qt_cronjobs_in IS NULL;
