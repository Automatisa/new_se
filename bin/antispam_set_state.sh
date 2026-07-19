#!/bin/sh
# Activa o detiene rspamd según el estado en /var/bulwark/run/antispam_state:
#   0 → deshabilitar: detener rspamd + deshabilitar en rc.conf.d
#   1 → habilitar:    arrancar rspamd + habilitar en rc.conf.d
STATE_FILE="/var/bulwark/run/antispam_state"
RC_CONF="/etc/rc.conf.d/rspamd"

state=$(cat "$STATE_FILE" 2>/dev/null | tr -d '[:space:]')
case "$state" in
  0)
    service rspamd stop > /dev/null 2>&1
    printf 'rspamd_enable="NO"\n' > "$RC_CONF"
    ;;
  1)
    printf 'rspamd_enable="YES"\n' > "$RC_CONF"
    service rspamd start > /dev/null 2>&1
    ;;
  *)
    exit 1
    ;;
esac
