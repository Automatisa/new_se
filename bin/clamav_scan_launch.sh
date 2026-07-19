#!/bin/sh
# Lanza clamav_scan_mailboxes.sh como daemon verdadero (FreeBSD daemon(8)).
# Retorna en milisegundos — no bloquea PHP-FPM aunque el escaneo tarde minutos.
LOCK=/var/bulwark/clamav/scan.lock
if [ -f "$LOCK" ]; then
    LPID=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$LPID" ] && kill -0 "$LPID" 2>/dev/null; then
        exit 0
    fi
    rm -f "$LOCK"
fi
/usr/sbin/daemon -f -p "$LOCK" /usr/local/bulwark/bin/clamav_scan_mailboxes.sh
exit 0
