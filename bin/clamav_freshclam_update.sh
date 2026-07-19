#!/bin/sh
LOG=/var/bulwark/clamav/freshclam_update.log
echo "=== Actualización iniciada: $(date) ===" >> "$LOG"
/usr/local/bin/freshclam --quiet >> "$LOG" 2>&1
echo "=== Actualización finalizada: $(date) ===" >> "$LOG"
chmod 644 "$LOG"
