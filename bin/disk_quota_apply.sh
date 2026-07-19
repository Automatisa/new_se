#!/bin/sh
# disk_quota_apply.sh — Aplica cuotas de disco UFS por cuenta (uid h_USERNAME).
#
# Invocado ÚNICAMENTE por privilege::run('disk_quota_apply'). Sin argumentos: lee
# /var/bulwark/run/disk_quota_req (root:bulwark 660), una línea por cuenta:
#     USERNAME|HARD_KB
# donde HARD_KB es el límite duro en bloques de 1 KB (0 = sin límite). El límite blando se
# fija igual al duro (enforcement estricto). El uid afectado es h_USERNAME.
#
# Requiere cuotas UFS activas en / (userquota en fstab + quota_enable=YES + reboot).

REQ=/var/bulwark/run/disk_quota_req
[ -f "$REQ" ] || exit 1

# ¿están activas las cuotas en / ?
if ! quota -v root >/dev/null 2>&1 && ! repquota -u / >/dev/null 2>&1; then
    echo "quotas no activas en /" >&2
    exit 2
fi

RC=0
while IFS='|' read -r USERNAME HARD_KB; do
    [ -n "$USERNAME" ] || continue
    echo "$USERNAME" | grep -Eq '^[a-zA-Z0-9_-]+$' || { RC=3; continue; }
    echo "$HARD_KB"  | grep -Eq '^[0-9]+$'          || HARD_KB=0
    SYSUSER="h_${USERNAME}"
    id "$SYSUSER" >/dev/null 2>&1 || { RC=4; continue; }
    # soft = hard (0:0 = ilimitado). Formato edquota: /fs:bsoft:bhard:isoft:ihard
    /usr/sbin/edquota -u -e "/:${HARD_KB}:${HARD_KB}:0:0" "$SYSUSER" || RC=5
done < "$REQ"

rm -f "$REQ"
exit $RC
