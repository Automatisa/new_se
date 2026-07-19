#!/bin/sh
# sysmail_alias_apply.sh — aplica el destino del correo del SISTEMA (root/postmaster) a
# /etc/mail/aliases. Lo usa el panel (mail_admin) vía doas. NUNCA acepta argumentos del caller:
# el destino se lee del ajuste 'system_mail_to' de x_settings (una sola fuente de verdad).
#
# Reglas:
#   - Si el ajuste es un email válido y su dominio != FQDN del panel -> `root: <email>`.
#     (dominio == FQDN provocaría bucle postmaster->root->postmaster@FQDN, ya que el FQDN es local).
#   - Si está vacío / no válido / mismo FQDN -> se quita el alias: el correo de sistema queda en
#     el buzón local /var/mail/root (legible en el servidor). En ningún caso rebota.
ALIASES=/etc/mail/aliases

# Leer el destino de la BD (creds en cnf/db.php, solo root). Se elimina TODO espacio/salto de
# línea para que un valor no pueda inyectar líneas extra en el fichero de alias.
DEST=$(php -r '
    include "/usr/local/bulwark/cnf/db.php";
    try {
        $d = new PDO("mysql:host=".$host.";dbname=".$dbname, $user, $pass);
        $s = $d->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc=\"system_mail_to\" LIMIT 1");
        echo preg_replace("/\s+/", "", (string)$s->fetchColumn());
    } catch (Exception $e) {}
' 2>/dev/null)

FQDN=$(postconf -h myhostname 2>/dev/null)
DOMAIN="${DEST##*@}"

# Quitar cualquier línea root: previa.
[ -f "$ALIASES" ] && sed -i '' -e '/^[[:space:]]*root:/d' "$ALIASES"

if [ -n "$DEST" ] \
   && echo "$DEST" | grep -qE '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' \
   && [ "$DOMAIN" != "$FQDN" ]; then
    printf 'root: %s\n' "$DEST" >> "$ALIASES"
fi

newaliases 2>/dev/null
exit 0
