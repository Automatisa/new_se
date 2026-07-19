# Migraciones incrementales

Cada cambio de **esquema de BD** o de **configuración del sistema** que deba aplicarse a instalaciones
ya existentes (no solo a instalaciones nuevas) va aquí como un fichero numerado. El corredor
`bin/db_migrate.php` los aplica **en orden**, **una sola vez** cada uno, y registra los aplicados en
la tabla `x_migrations`.

## Nombres
`NNNN_descripcion.sql` o `NNNN_descripcion.sh` — `NNNN` = 4 dígitos, correlativo (0001, 0002, …).
El orden lo da el nombre; SQL y SH se intercalan por número.

- **`.sql`** → cambios de **esquema/datos**. Se ejecuta sentencia a sentencia. **Idempotente**:
  usa `INSERT ... WHERE NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, y para columnas comprueba antes
  (ver ejemplo) — MyISAM no tiene transacciones DDL.
- **`.sh`** → cambios de **configuración/servicios** (proftpd, named/BIND, postfix, php-fpm…).
  Corre como **root**. Útil cuando una versión del panel **o de un paquete del sistema** cambia la
  estructura de un config (p. ej. BIND que cambia `named.conf`). Debe ser **idempotente** y salir 0
  en éxito (rc≠0 detiene la migración y no se marca como aplicada).

## Cómo se aplican
- **Instalación nueva**: `bulwark_core.sql` ya trae el esquema al día, así que el instalador ejecuta
  `php bin/db_migrate.php --baseline` → marca TODAS las migraciones como aplicadas **sin ejecutarlas**.
- **Actualización** (`git pull` desde el módulo updates): `panel_update.sh` ejecuta
  `php bin/db_migrate.php` → aplica solo las que falten.

## Reglas
1. **Nunca** edites una migración ya publicada (podría estar aplicada en algún sitio). Crea una nueva.
2. Todo cambio de esquema en `bulwark_core.sql` que afecte a instalaciones existentes necesita su
   migración equivalente aquí.
3. Las configuraciones **dinámicas** (zonas DNS, vhosts Apache, pools FPM) las regenera el daemon
   solo; NO necesitan migración. Solo migran los configs **estáticos** colocados por el instalador.

## Ejemplo de migración de configuración (.sh) — cambio de estructura de un config del sistema
```sh
#!/bin/sh
# 0007_bind_listenon_v2.sh — ejemplo: adaptar named.conf a un cambio de estructura de BIND.
CONF="/usr/local/etc/bulwark/bind/named.conf"
[ -f "$CONF" ] || exit 0
# Idempotente: solo actúa si el patrón viejo está presente.
if grep -q 'PATRON_VIEJO' "$CONF"; then
    cp "$CONF" "$CONF.bak.$(date +%s)"          # copia de seguridad
    sed -i '' -e 's/PATRON_VIEJO/PATRON_NUEVO/' "$CONF"
    named-checkconf "$CONF" || exit 1           # validar antes de recargar
    service named reload
fi
exit 0
```
