#!/bin/sh
# preconf_migrate.sh — Migraciones de config del sistema tras un salto de MAYOR de un paquete.
#
# Hermano de las migraciones de BD (bin/db_migrate.php, FIX-180), pero para ficheros de config:
# cuando un paquete cambia de mayor su config puede cambiar de estructura (p.ej. dovecot 2.3→2.4,
# postfix con parámetros nuevos, BIND con directivas nuevas). Este dispatcher ejecuta, si existe,
# el script de migración específico para ese paquete y mayor destino.
#
# Uso:  preconf_migrate.sh <pkg> <mayor_destino>     (lo llama pkg_pin.sh verify-major)
#
# Convención: preconf/pkg-migrations/<pkg>-<mayor>.sh   (p.ej. dovecot-2.4.sh, postfix-4.sh)
#   - Idempotente: puede re-ejecutarse sin romper (comprobar antes de tocar).
#   - Registra lo que hace en stdout (se captura en el last_action.log del panel).
#   - Sale 0 si OK; !=0 aborta y el panel muestra el fallo.
# Si no hay script para ese paquete+mayor, no hay nada que migrar (salida 0, informativo).

set -u

PKG="${1:-}"
MAJ="${2:-}"
DIR="/usr/local/bulwark/preconf/pkg-migrations"

if [ -z "$PKG" ] || [ -z "$MAJ" ]; then
    echo "preconf_migrate: uso: $0 <pkg> <mayor>" >&2
    exit 1
fi

# Whitelist de caracteres para no construir rutas raras a partir de los argumentos.
case "$PKG$MAJ" in
    *[!A-Za-z0-9._-]*) echo "preconf_migrate: argumentos inválidos" >&2; exit 1 ;;
esac

SCRIPT="$DIR/${PKG}-${MAJ}.sh"

if [ -f "$SCRIPT" ]; then
    echo "preconf_migrate: aplicando $SCRIPT"
    /bin/sh "$SCRIPT"
    rc=$?
    echo "preconf_migrate: $SCRIPT terminó rc=$rc"
    exit $rc
fi

echo "preconf_migrate: sin migración de config para $PKG mayor $MAJ (nada que hacer)"
exit 0
