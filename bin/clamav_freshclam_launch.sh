#!/bin/sh
LOCK=/var/bulwark/clamav/freshclam.lock
if [ -f "$LOCK" ]; then
    LPID=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$LPID" ] && kill -0 "$LPID" 2>/dev/null; then
        exit 0
    fi
    rm -f "$LOCK"
fi
/usr/sbin/daemon -f -p "$LOCK" /usr/local/bulwark/bin/clamav_freshclam_update.sh
exit 0
