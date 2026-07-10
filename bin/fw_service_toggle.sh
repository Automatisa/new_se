#!/bin/sh
# fw_service_toggle.sh — Activa/desactiva pf o SSHGuard desde el panel (fw_admin).
#
# Invocado ÚNICAMENTE por privilege::run('fw_service_toggle'). Sin argumentos: lee la orden
# de /var/sentora/run/fw_service_toggle_req (una línea "SERVICE ACTION"):
#     SERVICE = pf | sshguard      ACTION = on | off
# Validación estricta (solo esos valores). Aplica service onestart/onestop + sysrc para que
# el estado persista tras un reinicio.
#
# NOTA: desactivar pf deja el servidor SIN cortafuegos (todo el tráfico pasa) — es lo que el
# admin pide con "PF Enabled: No". No bloquea SSH: pf desactivado = todo permitido.

REQ=/var/sentora/run/fw_service_toggle_req
[ -f "$REQ" ] || exit 1

LINE=$(head -1 "$REQ" 2>/dev/null | tr -d '\r\n')
rm -f "$REQ"

SVC=$(printf '%s' "$LINE" | awk '{print $1}')
ACT=$(printf '%s' "$LINE" | awk '{print $2}')

case "$SVC" in pf|sshguard) ;; *) exit 2 ;; esac
case "$ACT" in on|off)      ;; *) exit 3 ;; esac

if [ "$ACT" = "on" ]; then
    sysrc "${SVC}_enable=YES" >/dev/null 2>&1
    service "$SVC" onestart   >/dev/null 2>&1
else
    service "$SVC" onestop     >/dev/null 2>&1
    sysrc "${SVC}_enable=NO"   >/dev/null 2>&1
fi

exit 0
