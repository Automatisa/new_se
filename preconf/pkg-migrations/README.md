# Migraciones de config del sistema (preconf) por salto de MAYOR de paquete

Hermano de las migraciones de BD (`preconf/migrations/`, ver `bin/db_migrate.php`, FIX-180), pero
para ficheros de **config del sistema**: cuando un paquete gestionado salta de versión **mayor**,
su configuración puede cambiar de estructura y hay que ajustarla para que el servicio arranque.

## Cómo funciona

Al pulsar **"Verificar y actualizar"** en el panel (módulo *updates*) para un paquete con una nueva
mayor disponible, `bin/pkg_pin.sh verify-major <pkg>` hace `unlock → pkg upgrade → relock` y luego
llama a `bin/preconf_migrate.sh <pkg> <mayor_destino>`, que ejecuta el script de este directorio:

```
preconf/pkg-migrations/<pkg>-<mayor>.sh
```

Ejemplos: `dovecot-mysql-2.4.sh`, `postfix-4.sh`, `bind920-921.sh`.

Si no existe script para ese paquete+mayor, no hay nada que migrar (salida 0, informativa).

## Reglas para escribir una migración

- **Idempotente:** puede re-ejecutarse sin romper (comprobar el estado antes de tocar).
- **Registra en stdout** lo que hace (se captura en el log de la última acción del panel).
- **Sale 0** si todo fue bien; cualquier otro código aborta y el panel muestra el fallo.
- Hacer copia de seguridad del fichero de config antes de reescribirlo (`.bak-<fecha>`).
- No asumir rutas: leerlas de los ajustes de Sentora cuando aplique.

## Paquetes gestionados (pin de mayor)

La whitelist vive en `bin/pkg_pin.sh` (variable `MANAGED`) y su espejo de validación en el
controlador del módulo *updates* (`const MANAGED`). Piloto actual: `dovecot-mysql`, `redis`.
Los paquetes con la mayor en el nombre (php84, bind920, apache24, mysql84) **no** se gestionan
aquí: `pkg upgrade` ya sólo les mueve subversiones.
