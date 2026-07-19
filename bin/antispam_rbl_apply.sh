#!/bin/sh
# Aplica configuración Spamhaus DQS a rspamd.
# Lee key y estado desde Redis; nunca acepta argumentos del caller.
REDIS=/usr/local/bin/redis-cli
RBL_CONF="/usr/local/etc/rspamd/local.d/rbl.conf"

# Auth ACL de Redis (usuario 'panel'). Corre como root, lee la clave de cnf/ (640 root:bulwark).
RPASS=$(head -1 /usr/local/bulwark/cnf/redis.pass 2>/dev/null)
if [ -n "$RPASS" ]; then
    RAUTH="--user panel -a $RPASS --no-auth-warning"
else
    RAUTH=""
fi

enabled=$($REDIS $RAUTH HGET bulwark:antispam:spamhaus enabled 2>/dev/null | tr -d '[:space:]')
key=$($REDIS $RAUTH HGET bulwark:antispam:spamhaus key 2>/dev/null | tr -d '[:space:]')

if [ "$enabled" = "1" ] && [ -n "$key" ]; then
    # Validar formato: solo letras minúsculas y dígitos, 10-50 chars
    if ! echo "$key" | grep -qE '^[a-z0-9]{10,50}$'; then
        exit 2
    fi

    cat > "$RBL_CONF" << CONFEOF
# Bulwark: Spamhaus DQS — generado automáticamente, no editar.
rbls {
  spamhaus {
    enabled = false;
  }
  spamhaus_dqs_zen {
    rbl = "${key}.zen.dq.spamhaus.net";
    checks = ["from", "received"];
    symbols_prefixes {
      received = "RECEIVED_DQS";
      from     = "RBL_DQS";
    }
    returncodes {
      SPAMHAUS_SBL     = "127.0.0.2";
      SPAMHAUS_SBL_CSS = "127.0.0.3";
      SPAMHAUS_XBL     = ["127.0.0.4", "127.0.0.5", "127.0.0.6", "127.0.0.7"];
      SPAMHAUS_PBL     = ["127.0.0.10", "127.0.0.11"];
      SPAMHAUS_DROP    = "127.0.0.9";
    }
  }
  spamhaus_dqs_dbl {
    rbl      = "${key}.dbl.dq.spamhaus.net";
    no_ip    = true;
    checks   = ["emails", "replyto", "urls"];
    returncodes {
      DBL_SPAM    = "127.0.1.2";
      DBL_PHISH   = "127.0.1.4";
      DBL_MALWARE = "127.0.1.5";
      DBL_ABUSE   = "127.0.1.6";
    }
  }
}
CONFEOF
    /usr/sbin/service rspamd reload > /dev/null 2>&1
    exit 0
fi

# Deshabilitado: eliminar override para volver a defaults
rm -f "$RBL_CONF"
/usr/sbin/service rspamd reload > /dev/null 2>&1
exit 0
