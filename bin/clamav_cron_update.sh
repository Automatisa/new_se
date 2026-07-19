#!/bin/sh
SCAN_CONF=/var/bulwark/clamav/scan_schedule.conf
CHECKS_CONF=/var/bulwark/clamav/freshclam_checks.conf
CHECKS=4
if [ -f "$CHECKS_CONF" ]; then
    VAL=$(head -1 "$CHECKS_CONF" | tr -d '\r\n ' | grep -E '^[0-9]+$')
    [ -n "$VAL" ] && CHECKS="$VAL"
fi
FCCONF=/usr/local/etc/freshclam.conf
if [ -f "$FCCONF" ]; then
    grep -q '^Checks ' "$FCCONF" && \
        sed -i '' "s/^Checks .*/Checks ${CHECKS}/" "$FCCONF" || \
        echo "Checks ${CHECKS}" >> "$FCCONF"
    service clamav_freshclam restart > /dev/null 2>&1
fi
SCAN_SCHED=""
[ -f "$SCAN_CONF" ] && SCAN_SCHED=$(head -1 "$SCAN_CONF" | tr -d '\r')
CTAB=$(crontab -l -u root 2>/dev/null | grep -v 'clamav_scan_mailboxes')
if [ -n "$SCAN_SCHED" ] && [ "$SCAN_SCHED" != "disable" ]; then
    printf '%s\n%s /usr/local/bulwark/bin/clamav_scan_mailboxes.sh\n' \
        "$CTAB" "$SCAN_SCHED" | crontab -u root -
else
    printf '%s\n' "$CTAB" | crontab -u root - 2>/dev/null
fi
