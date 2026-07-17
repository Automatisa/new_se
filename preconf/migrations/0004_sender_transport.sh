#!/bin/sh
# 0004_sender_transport.sh — Crea la tabla sentora_postfix.sender_transport (multi-IP Fase 3b:
# transporte de ENVÍO por dominio, para sender_dependent_default_transport_maps). Idempotente.
# El usuario del panel tiene permiso de CREATE/INSERT en sentora_postfix (patrón de los módulos
# de correo). En instalaciones nuevas la tabla ya viene en sentora_postfix.sql.

DBPHP=/usr/local/sentora/cnf/db.php
U=$(php -r "include \"$DBPHP\"; echo \$user;")
P=$(php -r "include \"$DBPHP\"; echo \$pass;")
H=$(php -r "include \"$DBPHP\"; echo \$host;")

mysql -u"$U" -p"$P" -h"$H" -e "CREATE TABLE IF NOT EXISTS sentora_postfix.sender_transport (
  domain varchar(255) NOT NULL,
  transport varchar(64) NOT NULL,
  PRIMARY KEY (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='Multi-IP: IP de envio por dominio'" 2>/dev/null

echo "sender_transport asegurada en sentora_postfix"
exit 0
