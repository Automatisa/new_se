#!/bin/sh
#
# Sentora FreeBSD Installer
# =========================
# Instala y configura Sentora en FreeBSD 14.x
#
# Rutas:
#   /usr/local/sentora       -> código del panel
#   /usr/local/etc/sentora   -> configuración (apache, bind, dovecot, postfix...)
#   /var/sentora             -> datos variables (logs, sesiones, vmail, etc.)
#
# Uso: sh install_sentora.sh
#

set -e

###############################################################################
# CONSTANTES
###############################################################################
PANEL_PATH="/usr/local/sentora"
PANEL_DATA="/var/sentora"
PANEL_CONF="/usr/local/etc/sentora"
VMAIL_UID=2000
VMAIL_GID=2000
GIT_REPO="https://github.com/Automatisa/new_se.git"

###############################################################################
# COLORES
###############################################################################
BOLD="\033[1m"
GREEN="\033[1;32m"
YELLOW="\033[1;33m"
RED="\033[1;31m"
RESET="\033[0m"

ok()   { printf "${GREEN}[OK]${RESET} %s\n" "$1"; }
info() { printf "${BOLD}---${RESET} %s\n" "$1"; }
warn() { printf "${YELLOW}[!]${RESET} %s\n" "$1"; }
die()  { printf "${RED}[ERROR]${RESET} %s\n" "$1"; exit 1; }

###############################################################################
# 1. COMPROBACIONES INICIALES
###############################################################################
info "Comprobando requisitos..."

[ "$(uname -s)" = "FreeBSD" ] || die "Este instalador es solo para FreeBSD."
[ "$(id -u)" -eq 0 ]         || die "Debes ejecutar este script como root."

OS_VER=$(freebsd-version | cut -d- -f1)
ARCH=$(uname -m)
ok "FreeBSD $OS_VER $ARCH detectado"

###############################################################################
# 2. INFORMACIÓN INTERACTIVA
###############################################################################
echo ""
echo "################################################################"
echo "#              Sentora FreeBSD Installer                       #"
echo "################################################################"
echo ""

# Detectar IP local
LOCAL_IP=$(ifconfig | awk '/inet /{print $2}' | grep -v '^127' | head -1)

info "Configuración del servidor"
printf "FQDN del servidor (ej: panel.ejemplo.com): "; read -r PANEL_FQDN
printf "IP del servidor [%s]: " "$LOCAL_IP";          read -r SERVER_IP
SERVER_IP="${SERVER_IP:-$LOCAL_IP}"
printf "Email del postmaster [postmaster@%s]: " "$PANEL_FQDN"; read -r POSTMASTER_EMAIL
POSTMASTER_EMAIL="${POSTMASTER_EMAIL:-postmaster@$PANEL_FQDN}"
printf "Zona horaria (ej: Europe/Madrid) [UTC]: ";    read -r TIMEZONE
TIMEZONE="${TIMEZONE:-UTC}"

echo ""
info "Configuración DNS (nameservers compartidos del panel)"
# Dominio proveedor por defecto = últimos dos segmentos del FQDN (panel.tudominio.com -> tudominio.com)
_DEF_PROVIDER=$(echo "$PANEL_FQDN" | awk -F. '{if (NF>=2) print $(NF-1)"."$NF; else print $0}')
printf "Dominio proveedor (zona base autoritativa) [%s]: " "$_DEF_PROVIDER"; read -r DNS_PROVIDER_DOMAIN
DNS_PROVIDER_DOMAIN="${DNS_PROVIDER_DOMAIN:-$_DEF_PROVIDER}"
printf "Nameserver 1 [ns1.%s]: " "$DNS_PROVIDER_DOMAIN"; read -r DNS_NS1
DNS_NS1="${DNS_NS1:-ns1.$DNS_PROVIDER_DOMAIN}"
printf "Nameserver 2 [ns2.%s]: " "$DNS_PROVIDER_DOMAIN"; read -r DNS_NS2
DNS_NS2="${DNS_NS2:-ns2.$DNS_PROVIDER_DOMAIN}"
printf "IP de ns1 [%s]: " "$SERVER_IP"; read -r DNS_NS1_IP
DNS_NS1_IP="${DNS_NS1_IP:-$SERVER_IP}"
printf "IP de ns2 [%s]: " "$SERVER_IP"; read -r DNS_NS2_IP
DNS_NS2_IP="${DNS_NS2_IP:-$SERVER_IP}"

# Cluster DNS (Fase 2): rol de este nodo. Primario = crea la zona base y genera la
# clave TSIG del cluster. Secundario = se une a un cluster existente (no recrea la
# zona base; añade ns2/panel2 a la del primario y esclaviza sus zonas por AXFR).
printf "¿Nodo DNS PRIMARIO o SECUNDARIO del cluster? [P/s]: "; read -r NODE_ROLE
NODE_ROLE=$(printf '%s' "${NODE_ROLE:-P}" | tr '[:lower:]' '[:upper:]')
if [ "$NODE_ROLE" = "S" ]; then
    printf "URL de la API del nodo primario (ej: https://panel1.%s/bin/api.php): " "$DNS_PROVIDER_DOMAIN"; read -r PRIMARY_API_URL
    printf "Token del CLUSTER del primario (ajuste dns_cluster_token del primario): "; read -r CLUSTER_TOKEN
    printf "Nombre (hostname) del primario (ej: panel1.%s): " "$DNS_PROVIDER_DOMAIN"; read -r PRIMARY_NAME
    printf "IP del nodo primario: "; read -r PRIMARY_IP
fi

echo ""
info "Contraseña del administrador del panel (zadmin)"
printf "Contraseña zadmin: "; stty -echo; read -r ZADMIN_PASSWORD; stty echo; echo
printf "Confirmar:         "; stty -echo; read -r ZADMIN_PASSWORD2; stty echo; echo
[ "$ZADMIN_PASSWORD" = "$ZADMIN_PASSWORD2" ] || die "Las contraseñas no coinciden."

echo ""
info "Configuración de MySQL"
printf "¿Tiene MySQL ya instalado y en ejecución? (s/N): "; read -r HAS_MYSQL
if echo "$HAS_MYSQL" | grep -qi "^s"; then
    printf "Contraseña root MySQL actual (vacía si ninguna): "; stty -echo; read -r MYSQL_ROOT_PASS_EXISTING; stty echo; echo
    MYSQL_EXISTING=true
else
    MYSQL_EXISTING=false
fi

###############################################################################
# 3. GENERAR CONTRASEÑAS
###############################################################################
info "Generando contraseñas..."

randpass() { LC_ALL=C tr -dc 'a-zA-Z0-9' < /dev/urandom | fold -w "$1" | head -1; }

MYSQL_ROOT_PASS=$(randpass 20)
SENTORA_DB_PASS=$(randpass 24)
POSTFIX_DB_PASS=$(randpass 24)
ROUNDCUBE_DB_PASS=$(randpass 24)
PROFTPD_DB_PASS=$(randpass 24)
ROUNDCUBE_DESKEY=$(randpass 24)
PHPMYADMIN_SECRET=$(randpass 32)

if [ "$MYSQL_EXISTING" = "true" ]; then
    MYSQL_ROOT_PASS="$MYSQL_ROOT_PASS_EXISTING"
fi

mkdir -p "$PANEL_DATA"
# 0755: los servicios (bind, dovecot, rspamd…) deben poder atravesar hasta sus
# subdirectorios de logs/datos. Los ficheros sensibles llevan sus propios 600.
chmod 755 "$PANEL_DATA"
cat > "$PANEL_DATA/install-passwords.txt" <<PWDEOF
# Sentora Install — Contraseñas generadas
# MANTENER ESTE ARCHIVO SEGURO
MySQL root:         $MYSQL_ROOT_PASS
Panel DB user:      sentora_panel / $SENTORA_DB_PASS
Postfix DB user:    postfix / $POSTFIX_DB_PASS
Roundcube DB user:  roundcube / $ROUNDCUBE_DB_PASS
ProFTPD DB user:    proftpd / $PROFTPD_DB_PASS
zadmin panel:       $ZADMIN_PASSWORD
PWDEOF
chmod 600 "$PANEL_DATA/install-passwords.txt"
ok "Contraseñas guardadas en $PANEL_DATA/install-passwords.txt"

###############################################################################
# 4. PAQUETES
###############################################################################
info "Actualizando repositorio pkg..."
pkg update -f
pkg upgrade -y
pkg install -y git

info "Instalando paquetes base..."
# sendmail (base) se desactiva antes de instalar postfix
sysrc sendmail_enable="NONE"
sysrc sendmail_submit_enable="NO"
sysrc sendmail_outbound_enable="NO"
sysrc sendmail_msp_queue_enable="NO"
service sendmail stop 2>/dev/null || true
sysrc daily_clean_hoststat_enable="NO"
sysrc daily_status_mail_rejects_enable="NO"
sysrc daily_status_include_submit_mailq="NO"
sysrc daily_submit_queuerun="NO"

# RACCT/RCTL: contabilidad y límites de recursos por usuario (contiene DoS de un inquilino:
# fork-bombs, RAM/CPU). Se aplica por-usuario desde el paquete (rctl_manager en el daemon).
# Requiere reinicio para activarse (es un tunable del loader).
if ! grep -q '^kern.racct.enable=' /boot/loader.conf 2>/dev/null; then
    printf 'kern.racct.enable="1"\n' >> /boot/loader.conf
    warn "RACCT activado en /boot/loader.conf — los limites de recursos por usuario se aplicaran tras el proximo REINICIO."
fi

# Endurecimiento del sistema (Nivel 0): reduce el daño que puede hacer el código de un
# inquilino (aunque use exec vía PHP/cron) al resto de clientes y al sistema.
info "Aplicando endurecimiento de seguridad del sistema (sysctl + noexec)..."
for _kv in \
    "security.bsd.see_other_uids=0" \
    "security.bsd.see_other_gids=0" \
    "security.bsd.unprivileged_proc_debug=0" \
    "security.bsd.hardlink_check_uid=1" \
    "security.bsd.hardlink_check_gid=1"; do
    _k=${_kv%%=*}
    grep -q "^${_k}=" /etc/sysctl.conf 2>/dev/null || printf '%s\n' "$_kv" >> /etc/sysctl.conf
    sysctl "$_kv" >/dev/null 2>&1
done
# /tmp y los datos de hosting montados noexec,nosuid,nodev: impide ejecutar binarios subidos
# y escalar por SUID desde directorios donde escriben los clientes. nullfs self-mount porque
# están en el mismo UFS que /. No afecta a PHP (interpretado) ni a sockets (p.ej. mysql.sock).
grep -q 'hostdata[[:space:]].*nullfs' /etc/fstab 2>/dev/null || \
    printf '/var/sentora/hostdata\t/var/sentora/hostdata\tnullfs\trw,noexec,nosuid,nodev\t0\t0\n' >> /etc/fstab
grep -q '^/tmp[[:space:]].*nullfs' /etc/fstab 2>/dev/null || \
    printf '/tmp\t/tmp\tnullfs\trw,noexec,nosuid,nodev\t0\t0\n' >> /etc/fstab
# /var/tmp también es world-writable (1777): sin noexec, un inquilino podría subir un binario
# ahí y ejecutarlo (staging de exploits). Cerrarlo igual que /tmp.
grep -q '^/var/tmp[[:space:]].*nullfs' /etc/fstab 2>/dev/null || \
    printf '/var/tmp\t/var/tmp\tnullfs\trw,noexec,nosuid,nodev\t0\t0\n' >> /etc/fstab
[ -d /var/sentora/hostdata ] && ! mount | grep -q 'hostdata.*noexec' && \
    mount -t nullfs -o noexec,nosuid,nodev /var/sentora/hostdata /var/sentora/hostdata 2>/dev/null
mount | grep -q ' /tmp .*noexec' || mount -t nullfs -o noexec,nosuid,nodev /tmp /tmp 2>/dev/null
mount | grep -q ' /var/tmp .*noexec' || mount -t nullfs -o noexec,nosuid,nodev /var/tmp /var/tmp 2>/dev/null

# Base de datos
if [ "$MYSQL_EXISTING" != "true" ]; then
    pkg install -y mysql84-server
fi

# Postfix con soporte MySQL
pkg install -y postfix-mysql

# Dovecot + MySQL + Pigeonhole (sieve)
pkg install -y dovecot-mysql dovecot-pigeonhole-mysql

# Apache + PHP-FPM + módulos necesarios
pkg install -y apache24 \
    php84 php84-mysqli php84-gd php84-curl \
    php84-mbstring php84-xml php84-zip php84-intl php84-opcache \
    php84-session php84-filter php84-pdo php84-pdo_mysql php84-posix \
    php84-dom php84-iconv php84-pecl-redis

# BIND
pkg install -y bind920

# Aplicaciones web
pkg install -y phpMyAdmin5-php84 roundcube-php84

# OpenDKIM — firma DKIM del correo saliente
pkg install -y opendkim

# Antispam: rspamd + Redis
pkg install -y rspamd redis

# ProFTPD con soporte MySQL
pkg install -y proftpd proftpd-mod_sql_mysql

# Cortafuegos: SSHGuard (protección fuerza bruta sobre pf)
pkg install -y sshguard

# Utilidades
# 'zip' es imprescindible para el módulo de backups (backupmgr usa `zip -r9`); sin él,
# la copia falla con "File not found in temp directory!". (unzip ya viene en base FreeBSD
# en /usr/bin/unzip; lo usa el restaurador de cuentas y moduleadmin.)
pkg install -y doas webalizer bash zip

ok "Paquetes instalados"

###############################################################################
# 5. USUARIOS Y GRUPOS DEL SISTEMA
###############################################################################
info "Creando usuarios del sistema..."

# Grupo vmail
if ! pw groupshow vmail > /dev/null 2>&1; then
    pw groupadd vmail -g "$VMAIL_GID"
    ok "Grupo vmail creado (gid=$VMAIL_GID)"
fi

# Usuario vmail
if ! pw usershow vmail > /dev/null 2>&1; then
    pw useradd vmail \
        -u "$VMAIL_UID" \
        -g vmail \
        -d "$PANEL_DATA/vmail" \
        -s /usr/sbin/nologin \
        -c "Virtual Mail"
    ok "Usuario vmail creado (uid=$VMAIL_UID)"
fi

# Añadir www al grupo vmail (necesario para que PHP-FPM cree Maildir con chgrp)
pw groupmod vmail -m www
ok "Usuario www añadido al grupo vmail"

# Usuario vacation (para autorespuesta)
if ! pw usershow vacation > /dev/null 2>&1; then
    pw useradd vacation \
        -d /var/spool/vacation \
        -s /usr/sbin/nologin \
        -c "Vacation autoresponder"
    ok "Usuario vacation creado"
fi

# Grupo/usuario opendkim (el puerto FreeBSD de opendkim no los crea)
if ! pw groupshow opendkim > /dev/null 2>&1; then
    pw groupadd opendkim
    ok "Grupo opendkim creado"
fi
if ! pw usershow opendkim > /dev/null 2>&1; then
    pw useradd opendkim \
        -g opendkim \
        -d /var/run/milteropendkim \
        -s /usr/sbin/nologin \
        -c "OpenDKIM milter"
    ok "Usuario opendkim creado"
fi

###############################################################################
# 6. CLONAR SENTORA
###############################################################################
info "Clonando Sentora desde GitHub..."

if [ -d "$PANEL_PATH/.git" ]; then
    warn "Panel ya existe en $PANEL_PATH — actualizando con git pull"
    git -C "$PANEL_PATH" pull
else
    rm -rf "$PANEL_PATH"
    while true; do
        if git clone "$GIT_REPO" "$PANEL_PATH"; then
            break
        else
            printf "Error al clonar. (r) reintentar / (q) salir: "; read -r resp
            case "$resp" in [Qq]*) exit 3;; esac
        fi
    done
fi

# Limpiar archivos de test y temporales del repo
rm -rf "$PANEL_PATH/modules/*/tests/"
rm -rf "$PANEL_PATH/composer.json" "$PANEL_PATH/composer.lock"
ok "Panel clonado en $PANEL_PATH"

###############################################################################
# 7. ESTRUCTURA DE DIRECTORIOS
###############################################################################
info "Creando estructura de directorios..."

# Copiar preconf a PANEL_CONF
mkdir -p "$PANEL_CONF"
cp -rf "$PANEL_PATH/preconf/"* "$PANEL_CONF/"

# Directorios de datos
mkdir -p "$PANEL_DATA/hostdata"
mkdir -p "$PANEL_DATA/backups"
mkdir -p "$PANEL_DATA/temp"
mkdir -p "$PANEL_DATA/sessions"
mkdir -p "$PANEL_DATA/named/data"
mkdir -p "$PANEL_DATA/sieve"
mkdir -p "$PANEL_DATA/logs/bind"
mkdir -p "$PANEL_DATA/logs/dovecot"
mkdir -p "$PANEL_DATA/logs/domains"
mkdir -p "$PANEL_DATA/logs/postfix"
mkdir -p "$PANEL_DATA/logs/proftpd"
mkdir -p "$PANEL_DATA/logs/roundcube"

# Directorio raíz de correo (SGID: nuevas carpetas heredan grupo vmail)
mkdir -p "$PANEL_DATA/vmail"
chown vmail:vmail "$PANEL_DATA/vmail"
chmod 2770 "$PANEL_DATA/vmail"

# Permisos logs
chown -R vmail:vmail "$PANEL_DATA/logs/dovecot"
chown -R bind:bind   "$PANEL_DATA/logs/bind"
touch "$PANEL_DATA/logs/bind/bind.log" "$PANEL_DATA/logs/bind/debug.log"
chown bind:bind "$PANEL_DATA/logs/bind/bind.log" "$PANEL_DATA/logs/bind/debug.log"

# Sieve global
chown vmail:mail "$PANEL_DATA/sieve"
chmod 750 "$PANEL_DATA/sieve"

# Permisos del panel
chown -R root:wheel "$PANEL_PATH"
chown -R www:www    "$PANEL_PATH/etc/tmp"
chmod    1777       "$PANEL_DATA/temp"
chmod    733        "$PANEL_DATA/sessions"
chmod     +t        "$PANEL_DATA/sessions"
chown www:www       "$PANEL_DATA/hostdata"
chmod 0755          "$PANEL_DATA/hostdata"
chown www:www       "$PANEL_DATA/logs/roundcube"
touch "$PANEL_DATA/logs/sentora.log" "$PANEL_DATA/logs/sentora-access.log" \
      "$PANEL_DATA/logs/sentora-error.log" "$PANEL_DATA/logs/sentora-bandwidth.log" \
      "$PANEL_DATA/logs/php_errors.log"
chown www:www "$PANEL_DATA/logs/sentora.log" "$PANEL_DATA/logs/sentora-access.log" \
              "$PANEL_DATA/logs/sentora-error.log" "$PANEL_DATA/logs/sentora-bandwidth.log" \
              "$PANEL_DATA/logs/php_errors.log"

# Directorio de ficheros de petición privilegiada (fw, hosting) — root:www 750
# Los scripts privilegiados leen desde aquí; www escribe; nadie más accede.
mkdir -p "$PANEL_DATA/run"
chown root:www "$PANEL_DATA/run"
chmod 750 "$PANEL_DATA/run"

# Logs del cortafuegos (fw_status.json lo escribe fw_status_dump.sh como root, legible por www)
mkdir -p "$PANEL_DATA/logs/fw"

# bin/ viene del git clone; solo ajustar permisos de ejecución
chmod 750 "$PANEL_PATH/bin/"*
chown root:wheel "$PANEL_PATH/bin/"*

ok "Estructura de directorios creada"

###############################################################################
# 8. MARIADB / MYSQL
###############################################################################
info "Configurando MariaDB..."

if [ "$MYSQL_EXISTING" != "true" ]; then
    sysrc mysql_enable="YES"
    service mysql-server start
    echo "Esperando que MariaDB inicie..."
    sleep 5

    # En MariaDB recién instalado, root no tiene contraseña
    mysql -u root --connect-timeout=10 -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';" 2>/dev/null || \
    mysql -u root -p"$MYSQL_ROOT_PASS" --connect-timeout=10 -e "SELECT 1;" > /dev/null 2>&1 || \
    mysqladmin -u root password "$MYSQL_ROOT_PASS"

    mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASS" -e "DELETE FROM mysql.user WHERE User='root' AND Host != 'localhost';" 2>/dev/null || true
    mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASS" -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || true
    mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASS" -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || true
    mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASS" -e "FLUSH PRIVILEGES;" 2>/dev/null || true

    # Archivo my.cnf
    mkdir -p /usr/local/etc/mysql
    cat > /usr/local/etc/mysql/my.cnf <<MYCNF
[mysqld]
bind-address            = 127.0.0.1
secure-file-priv        = /var/tmp
max_allowed_packet      = 256M
innodb_buffer_pool_size = 128M
MYCNF
fi

MYSQL="mysql -h127.0.0.1 -uroot -p${MYSQL_ROOT_PASS}"

# Importar esquemas
info "Importando esquemas de base de datos..."
$MYSQL < "$PANEL_CONF/sentora-install/sql/sentora_core.sql"
$MYSQL < "$PANEL_CONF/sentora-install/sql/sentora_postfix.sql"
$MYSQL < "$PANEL_CONF/sentora-install/sql/sentora_proftpd.sql"
$MYSQL < "$PANEL_CONF/sentora-install/sql/sentora_roundcube.sql"
ok "Esquemas importados"

# Crear usuarios de DB
info "Creando usuarios de base de datos..."
$MYSQL -e "
-- Usuario del PANEL con privilegios acotados (NO root): puede gestionar bases/usuarios de
-- cliente (CREATE/DROP/CREATE USER/GRANT/RELOAD) pero NO tiene FILE (no LOAD_FILE/INTO OUTFILE),
-- ni SUPER/SHUTDOWN. Así, si db.php se filtrara, el daño es mucho menor que con root.
CREATE USER IF NOT EXISTS 'sentora_panel'@'127.0.0.1' IDENTIFIED BY '$SENTORA_DB_PASS';
CREATE USER IF NOT EXISTS 'sentora_panel'@'localhost' IDENTIFIED BY '$SENTORA_DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER, REFERENCES, CREATE USER, RELOAD, SHOW DATABASES ON *.* TO 'sentora_panel'@'127.0.0.1' WITH GRANT OPTION;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER, REFERENCES, CREATE USER, RELOAD, SHOW DATABASES ON *.* TO 'sentora_panel'@'localhost' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'postfix'@'localhost' IDENTIFIED BY '$POSTFIX_DB_PASS';
GRANT SELECT ON sentora_postfix.* TO 'postfix'@'localhost';
CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '$ROUNDCUBE_DB_PASS';
GRANT ALL PRIVILEGES ON sentora_roundcube.* TO 'roundcube'@'localhost';
CREATE USER IF NOT EXISTS 'proftpd'@'localhost' IDENTIFIED BY '$PROFTPD_DB_PASS';
GRANT SELECT ON sentora_proftpd.* TO 'proftpd'@'localhost';
FLUSH PRIVILEGES;
"
ok "Usuarios de DB creados"

# La contraseña de zadmin se fija más abajo con bin/setzadmin (tras crear db.php),
# que genera hash+salt coherentes con runtime_hash, la crypto key y la API key.

# Configurar x_settings clave
$MYSQL sentora_core -e "
UPDATE x_settings SET so_value_tx='postfix.php'     WHERE so_name_vc='mailserver_php';
UPDATE x_settings SET so_value_tx='sentora_postfix' WHERE so_name_vc='mailserver_db';
UPDATE x_settings SET so_value_tx='$PANEL_FQDN'     WHERE so_name_vc='sentora_domain';
UPDATE x_settings SET so_value_tx='604800'           WHERE so_name_vc='expire_ttl';
UPDATE x_settings SET so_value_tx='$DNS_PROVIDER_DOMAIN' WHERE so_name_vc='dns_provider_domain';
UPDATE x_settings SET so_value_tx='$DNS_NS1'            WHERE so_name_vc='dns_ns1';
UPDATE x_settings SET so_value_tx='$DNS_NS2'            WHERE so_name_vc='dns_ns2';
UPDATE x_settings SET so_value_tx='$DNS_NS1_IP'         WHERE so_name_vc='dns_ns1_ip';
UPDATE x_settings SET so_value_tx='$DNS_NS2_IP'         WHERE so_name_vc='dns_ns2_ip';
UPDATE x_settings SET so_value_tx='$SERVER_IP'          WHERE so_name_vc='server_ip';
"

# La plantilla de zona por defecto (x_dns_create, con :NS1:/:NS2:/:IP:/:DOMAIN:) ya
# viene completa en sentora_core.sql; aquí no se toca.
ok "x_settings configurados"

# db.php del panel
mkdir -p "$PANEL_PATH/cnf"
cat > "$PANEL_PATH/cnf/db.php" <<DBPHP
<?php
\$host   = '127.0.0.1';
\$dbname = 'sentora_core';
\$user   = 'sentora_panel';
\$pass   = '$SENTORA_DB_PASS';
?>
DBPHP
chmod 640 "$PANEL_PATH/cnf/db.php"
chown root:www "$PANEL_PATH/cnf/db.php"
ok "db.php configurado"

# Fijar contraseña de zadmin con la utilidad oficial: genera hash+salt (runtime_hash),
# la crypto key (cnf/security.php) y una API key nueva. Requiere cnf/db.php ya escrito.
php "$PANEL_PATH/bin/setzadmin" --set "$ZADMIN_PASSWORD" > /dev/null
chown root:www "$PANEL_PATH/cnf/security.php"
chmod 640 "$PANEL_PATH/cnf/security.php"
ok "Contraseña de zadmin fijada (setzadmin)"

###############################################################################
# 9. POSTFIX
###############################################################################
info "Configurando Postfix..."

# Reemplazar sendmail del sistema por el de Postfix
cat > /etc/mail/mailer.conf <<MAILERCF
sendmail        /usr/local/sbin/sendmail
send-mail       /usr/local/sbin/sendmail
mailq           /usr/local/sbin/sendmail
newaliases      /usr/local/sbin/sendmail
MAILERCF

# Copiar mapas MySQL de postfix a PANEL_CONF (ya copiados en paso 7),
# reemplazar contraseña y host
for f in "$PANEL_CONF/postfix/mysql-"*.cf; do
    sed -i '' \
        -e "s|host.*=.*localhost|hosts = 127.0.0.1|g" \
        -e "s|hosts.*=.*localhost|hosts = 127.0.0.1|g" \
        -e "s|password.*=.*!POSTFIX_PASSWORD!|password = $POSTFIX_DB_PASS|g" \
        -e "s|user.*=.*postfix|user = postfix|g" \
        "$f"
done

# Escribir main.cf con rutas FreeBSD correctas
cat > /usr/local/etc/postfix/main.cf <<MAINCF
# Postfix main.cf — FreeBSD / Sentora
# Generado por install_sentora.sh

compatibility_level = 3.11

# Ownership
mail_owner    = postfix
setgid_group  = maildrop

# Rutas FreeBSD
html_directory      = no
command_directory   = /usr/local/sbin
daemon_directory    = /usr/local/libexec/postfix
queue_directory     = /var/spool/postfix
sendmail_path       = /usr/local/sbin/sendmail
newaliases_path     = /usr/local/bin/newaliases
mailq_path          = /usr/local/bin/mailq
manpage_directory   = /usr/local/man
sample_directory    = no
readme_directory    = no
data_directory      = /var/db/postfix

# Red
inet_interfaces       = all
inet_protocols        = ipv4
myhostname            = $PANEL_FQDN
mydomain              = $PANEL_FQDN
mynetworks            = 127.0.0.0/8, ${SERVER_IP}/32
mydestination         = localhost.\$mydomain, localhost
delay_warning_time    = 4h

# Dominios virtuales via MySQL
relay_domains         = proxy:mysql:$PANEL_CONF/postfix/mysql-relay_domains_maps.cf
virtual_alias_maps    = proxy:mysql:$PANEL_CONF/postfix/mysql-virtual_alias_maps.cf,
                        regexp:$PANEL_CONF/postfix/virtual_regexp
virtual_mailbox_base  = $PANEL_DATA/vmail
virtual_mailbox_domains = proxy:mysql:$PANEL_CONF/postfix/mysql-virtual_domains_maps.cf
virtual_mailbox_maps  = proxy:mysql:$PANEL_CONF/postfix/mysql-virtual_mailbox_maps.cf
virtual_minimum_uid   = $VMAIL_UID
virtual_uid_maps      = static:$VMAIL_UID
virtual_gid_maps      = static:$VMAIL_GID
virtual_transport     = dovecot
dovecot_destination_recipient_limit = 1

# Alias
alias_maps      = hash:/etc/mail/aliases
alias_database  = hash:/etc/mail/aliases
recipient_delimiter = +

# SASL via Dovecot
smtpd_sasl_auth_enable       = yes
smtpd_sasl_security_options  = noanonymous
smtpd_sasl_type              = dovecot
smtpd_sasl_path              = private/auth
broken_sasl_auth_clients     = yes

# TLS (comentado hasta tener certificado real)
smtp_tls_security_level  = may
smtpd_tls_security_level = may
# smtpd_tls_key_file  = /usr/local/etc/ssl/sentora/mail.key
# smtpd_tls_cert_file = /usr/local/etc/ssl/sentora/mail.crt

# Restricciones
smtpd_helo_required = yes
disable_vrfy_command = yes
smtpd_data_restrictions = reject_unauth_pipelining
smtpd_banner = \$myhostname ESMTP
unknown_local_recipient_reject_code = 550

smtpd_client_restrictions    =
smtpd_helo_restrictions      =
smtpd_sender_restrictions    =
smtpd_recipient_restrictions =
    permit_sasl_authenticated,
    permit_mynetworks,
    reject_unauth_destination,
    reject_non_fqdn_sender,
    reject_non_fqdn_recipient,
    reject_unknown_recipient_domain

message_size_limit = 20480000
soft_bounce = no
MAINCF

# Escribir master.cf (corrección clave: user=vmail sin :mail)
cat > /usr/local/etc/postfix/master.cf <<MASTERCF
#
# Postfix master.cf — FreeBSD / Sentora
#
# ==========================================================================
# service type  private unpriv  chroot  wakeup  maxproc command + args
# ==========================================================================
smtp      inet  n       -       n       -       -       smtpd
submission inet n       -       n       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_client_restrictions=permit_sasl_authenticated,reject
pickup    fifo  n       -       n       60      1       pickup
  -o content_filter=
  -o receive_override_options=no_header_body_checks
cleanup   unix  n       -       n       -       0       cleanup
qmgr      fifo  n       -       n       300     1       qmgr
tlsmgr    unix  -       -       n       1000?   1       tlsmgr
rewrite   unix  -       -       n       -       -       trivial-rewrite
bounce    unix  -       -       n       -       0       bounce
defer     unix  -       -       n       -       0       bounce
trace     unix  -       -       n       -       0       bounce
verify    unix  -       -       n       -       1       verify
flush     unix  n       -       n       1000?   0       flush
proxymap  unix  -       -       n       -       -       proxymap
smtp      unix  -       -       n       -       -       smtp
relay     unix  -       -       n       -       -       smtp
        -o smtp_fallback_relay=
showq     unix  n       -       n       -       -       showq
error     unix  -       -       n       -       -       error
discard   unix  -       -       n       -       -       discard
local     unix  -       n       n       -       -       local
virtual   unix  -       n       n       -       -       virtual
lmtp      unix  -       -       n       -       -       lmtp
anvil     unix  -       -       n       -       1       anvil
scache    unix  -       -       n       -       1       scache
#
# Dovecot LDA — IMPORTANTE: user=vmail (sin :mail) para evitar setgid error
dovecot   unix  -       n       n       -       -       pipe
  flags=DRhu user=vmail argv=/usr/local/libexec/dovecot/deliver -d \${recipient}
#
# Vacation autorespuesta
vacation  unix  -       n       n       -       -       pipe
  flags=Rq user=vacation argv=/var/spool/vacation/vacation.pl -f \${sender} -- \${recipient}
MASTERCF

sysrc postfix_enable="YES"
ok "Postfix configurado"

###############################################################################
# 10. DOVECOT
###############################################################################
info "Configurando Dovecot..."

# dovecot.conf — basado en preconf con correcciones FreeBSD
cat > "$PANEL_CONF/dovecot2/dovecot.conf" <<DOVCF
## Dovecot config — FreeBSD / Sentora
listen = *
disable_plaintext_auth = no
ssl = no
log_timestamp = %Y-%m-%d %H:%M:%S
protocols = imap pop3 lmtp sieve
auth_mechanisms = plain login

passdb {
  driver = sql
  args = $PANEL_CONF/dovecot2/dovecot-mysql.conf
}
userdb {
  driver = prefetch
}
userdb {
  driver = sql
  args = $PANEL_CONF/dovecot2/dovecot-mysql.conf
}

mail_location = maildir:/var/sentora/vmail/%d/%n
mailbox_idle_check_interval = 30 secs
maildir_copy_with_hardlinks = yes
first_valid_uid = $VMAIL_UID

service imap-login {
  inet_listener imap {
    port = 143
  }
}
service pop3-login {
  inet_listener pop3 {
    port = 110
  }
}
service lmtp {
  unix_listener lmtp {
  }
}
service imap {
  vsz_limit = 256M
}
service auth {
  unix_listener auth-userdb {
    mode = 0666
    user = vmail
    group = mail
  }
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
}
service auth-worker {
}
service dict {
  unix_listener dict {
    mode = 0666
    user = vmail
    group = mail
  }
}
service stats {
  unix_listener stats-writer {
    mode = 0660
    user = vmail
    group = mail
  }
}
service managesieve-login {
  inet_listener sieve {
    port = 4190
  }
  service_count = 1
  vsz_limit = 64M
}
service managesieve {
}

lda_mailbox_autocreate = yes
lda_mailbox_autosubscribe = yes

protocol lda {
  mail_plugins = quota sieve
  postmaster_address = $POSTMASTER_EMAIL
}
protocol imap {
  mail_plugins = quota imap_quota trash
  imap_client_workarounds = delay-newmail
}
lmtp_save_to_detail_mailbox = yes
protocol lmtp {
  mail_plugins = quota sieve
}
protocol pop3 {
  mail_plugins = quota
  pop3_client_workarounds = outlook-no-nuls oe-ns-eoh
  pop3_uidl_format = %08Xu%08Xv
}
protocol sieve {
  managesieve_max_line_length = 65536
  managesieve_implementation_string = Dovecot Pigeonhole
  managesieve_max_compile_errors = 5
}

dict {
  quotadict = mysql:$PANEL_CONF/dovecot2/dovecot-dict-quota.conf
}

plugin {
  quota = maildir:User quota
  trash = $PANEL_CONF/dovecot2/dovecot-trash.conf
  sieve_global_path = $PANEL_DATA/sieve/globalfilter.sieve
  sieve = ~/dovecot.sieve
  sieve_dir = ~/sieve
  sieve_global_dir = $PANEL_DATA/sieve/
  sieve_max_script_size = 1M
}

log_path       = $PANEL_DATA/logs/dovecot/dovecot.log
info_log_path  = $PANEL_DATA/logs/dovecot/dovecot-info.log
debug_log_path = $PANEL_DATA/logs/dovecot/dovecot-debug.log
mail_debug = no
DOVCF

# dovecot-mysql.conf — IMPORTANTE: host=127.0.0.1 (no localhost, FreeBSD no tiene socket en /tmp)
cat > "$PANEL_CONF/dovecot2/dovecot-mysql.conf" <<DOVMYSQL
driver = mysql
connect = host=127.0.0.1 dbname=sentora_postfix user=postfix password=$POSTFIX_DB_PASS
default_pass_scheme = SHA512-CRYPT
password_query = SELECT username as user, password, concat('$PANEL_DATA/vmail/', maildir) as userdb_home, concat('maildir:$PANEL_DATA/vmail/', maildir) as userdb_mail, $VMAIL_UID as userdb_uid, $VMAIL_GID as userdb_gid, concat('*:bytes=', (quota*1024*1024)) AS userdb_quota_rule FROM mailbox WHERE username = '%u' AND active = '1';
user_query = SELECT concat('$PANEL_DATA/vmail/', maildir) as home, concat('maildir:$PANEL_DATA/vmail/', maildir) as mail, $VMAIL_UID AS uid, $VMAIL_GID AS gid, concat('*:bytes=', (quota*1024*1024)) AS quota_rule FROM mailbox WHERE username = '%u' AND active = '1';
DOVMYSQL

# dovecot-dict-quota.conf — también host=127.0.0.1
cat > "$PANEL_CONF/dovecot2/dovecot-dict-quota.conf" <<DOVQUOTA
connect = host=127.0.0.1 dbname=sentora_postfix user=postfix password=$POSTFIX_DB_PASS
map {
  pattern = priv/quota/storage
  table = quota2
  username_field = username
  value_field = bytes
}
map {
  pattern = priv/quota/messages
  table = quota2
  username_field = username
  value_field = messages
}
DOVQUOTA

# Proteger archivos con contraseña
chmod 640 "$PANEL_CONF/dovecot2/dovecot-mysql.conf" \
          "$PANEL_CONF/dovecot2/dovecot-dict-quota.conf"
chown root:dovecot "$PANEL_CONF/dovecot2/dovecot-mysql.conf" \
                   "$PANEL_CONF/dovecot2/dovecot-dict-quota.conf"

# Symlink en /usr/local/etc/dovecot/dovecot.conf
ln -sfn "$PANEL_CONF/dovecot2/dovecot.conf" /usr/local/etc/dovecot/dovecot.conf

# Copiar sieve global filter si existe en preconf
if [ -f "$PANEL_CONF/dovecot2/globalfilter.sieve" ]; then
    cp "$PANEL_CONF/dovecot2/globalfilter.sieve" "$PANEL_DATA/sieve/"
    chown vmail:vmail "$PANEL_DATA/sieve/globalfilter.sieve"
fi

sysrc dovecot_enable="YES"
sysrc dovecot_config="$PANEL_CONF/dovecot2/dovecot.conf"
ok "Dovecot configurado"

###############################################################################
# 10b. OPENDKIM
###############################################################################
info "Configurando OpenDKIM..."

mkdir -p /usr/local/etc/opendkim/keys
chown -R opendkim:opendkim /usr/local/etc/opendkim
chmod 750 /usr/local/etc/opendkim
chmod 750 /usr/local/etc/opendkim/keys

# Ficheros de mapas — vacíos; el daemon los rellena al crear dominios DNS
touch /usr/local/etc/opendkim/KeyTable
touch /usr/local/etc/opendkim/SigningTable
chown opendkim:opendkim /usr/local/etc/opendkim/KeyTable \
                        /usr/local/etc/opendkim/SigningTable
chmod 640 /usr/local/etc/opendkim/KeyTable \
          /usr/local/etc/opendkim/SigningTable

cat > /usr/local/etc/opendkim/TrustedHosts <<DKIMTH
127.0.0.1
localhost
$SERVER_IP
DKIMTH
chown opendkim:opendkim /usr/local/etc/opendkim/TrustedHosts
chmod 640 /usr/local/etc/opendkim/TrustedHosts

# En FreeBSD el servicio se llama milter-opendkim y lee /usr/local/etc/mail/opendkim.conf
cat > /usr/local/etc/mail/opendkim.conf <<DKIMCF
# OpenDKIM — gestionado por Sentora (dns_manager daemon hook)
Syslog              yes
SyslogSuccess       yes
LogWhy              yes

Canonicalization    relaxed/simple
Mode                sv
SubDomains          no
OversignHeaders     From

KeyTable            /usr/local/etc/opendkim/KeyTable
SigningTable        refile:/usr/local/etc/opendkim/SigningTable
ExternalIgnoreList  /usr/local/etc/opendkim/TrustedHosts
InternalHosts       /usr/local/etc/opendkim/TrustedHosts

Socket              inet:8891@127.0.0.1
PidFile             /var/run/milteropendkim/pid
UserID              opendkim:opendkim
UMask               022
DKIMCF

chown root:wheel /usr/local/etc/mail/opendkim.conf
chmod 644 /usr/local/etc/mail/opendkim.conf

# Añadir milter a Postfix para que firme el correo saliente
postconf -e 'milter_default_action = accept'
postconf -e 'milter_protocol = 6'
postconf -e 'smtpd_milters = inet:127.0.0.1:8891'
postconf -e 'non_smtpd_milters = inet:127.0.0.1:8891'

sysrc milteropendkim_enable="YES"
ok "OpenDKIM configurado"

###############################################################################
# 10c. REDIS
###############################################################################
info "Configurando Redis..."

# Configuración mínima: solo escucha en loopback, sin persistencia de disco
cat > /usr/local/etc/redis.conf <<REDISCF
bind 127.0.0.1
port 6379
daemonize yes
pidfile /var/run/redis/redis.pid
loglevel notice
logfile /var/log/redis/redis.log
databases 16
save ""
appendonly no
REDISCF

mkdir -p /var/log/redis
chown redis:redis /var/log/redis 2>/dev/null || chown nobody:nobody /var/log/redis

sysrc redis_enable="YES"
# Arrancar Redis ya: la configuración de rspamd/clamav usa redis-cli más abajo
service redis restart 2>/dev/null || service redis start
# Esperar a que Redis acepte conexiones
for _i in 1 2 3 4 5 6 7 8 9 10; do
    redis-cli ping > /dev/null 2>&1 && break
    sleep 1
done
ok "Redis configurado"

###############################################################################
# 10d. RSPAMD
###############################################################################
info "Configurando rspamd..."

# Integrar rspamd con Postfix como milter
postconf -e 'smtpd_milters = inet:127.0.0.1:8891, inet:127.0.0.1:11332'
postconf -e 'non_smtpd_milters = inet:127.0.0.1:8891'
postconf -e 'milter_mail_macros = i {mail_addr} {client_addr} {client_name} {auth_authen}'

# Configuración local de rspamd: usar Redis, activar greylisting y greylist en Redis
mkdir -p /usr/local/etc/rspamd/local.d

cat > /usr/local/etc/rspamd/local.d/redis.conf <<RSREDIS
servers = "127.0.0.1:6379";
RSREDIS

cat > /usr/local/etc/rspamd/local.d/classifier-bayes.conf <<RSBAYES
backend = "redis";
RSBAYES

cat > /usr/local/etc/rspamd/local.d/greylist.conf <<RSGREYLIST
enabled = true;
servers = "127.0.0.1:6379";
RSGREYLIST

# DNS: rspamd usa resolver externo con recursión para consultas RBL/DQS.
# El BIND local es autoritativo (recursion no) y no puede resolver zonas externas.
# El fichero dinámico lo gestiona el panel desde antispam_admin → Global Settings.
mkdir -p /var/sentora/rspamd
cat > /var/sentora/rspamd/options.inc << EOF
dns {
    nameserver = ["8.8.8.8:53:1", "8.8.4.4:53:1"];
    timeout = 2s;
    retransmits = 5;
    sockets = 16;
    connections = 4;
}
EOF
chown www:www /var/sentora/rspamd/options.inc

# local.d/options.inc es estático: solo incluye el fichero dinámico
cat > /usr/local/etc/rspamd/local.d/options.inc <<RSOPTS
# Sentora: configuración DNS gestionada desde el panel antispam_admin → Global Settings.
.include(try=true,priority=10) "/var/sentora/rspamd/options.inc"
RSOPTS

# Guardar DNS por defecto en Redis para que el panel los muestre
redis-cli HSET sentora:antispam:dns primary "8.8.8.8" secondary "8.8.4.4" > /dev/null

# Puntuaciones para símbolos Spamhaus DQS (sin esto los símbolos aparecen con score 0)
cat > /usr/local/etc/rspamd/local.d/groups.conf <<RSGROUPS
group "rbl" {
    symbols {
        "RBL_DQS_SPAMHAUS_SBL"          { weight = 6.0; description = "Spamhaus SBL (fuente spam directa)"; }
        "RBL_DQS_SPAMHAUS_SBL_CSS"      { weight = 3.0; description = "Spamhaus CSS (spam support services)"; }
        "RBL_DQS_SPAMHAUS_XBL"          { weight = 4.0; description = "Spamhaus XBL (botnet/exploit)"; }
        "RBL_DQS_SPAMHAUS_PBL"          { weight = 1.5; description = "Spamhaus PBL (IP usuario final)"; }
        "RBL_DQS_SPAMHAUS_DROP"         { weight = 8.0; description = "Spamhaus DROP (red robada)"; }
        "RECEIVED_DQS_SPAMHAUS_SBL"     { weight = 6.0; description = "Spamhaus SBL (received)"; }
        "RECEIVED_DQS_SPAMHAUS_SBL_CSS" { weight = 3.0; description = "Spamhaus CSS (received)"; }
        "RECEIVED_DQS_SPAMHAUS_XBL"     { weight = 4.0; description = "Spamhaus XBL (received)"; }
        "RECEIVED_DQS_SPAMHAUS_PBL"     { weight = 1.5; description = "Spamhaus PBL (received)"; }
        "RECEIVED_DQS_SPAMHAUS_DROP"    { weight = 8.0; description = "Spamhaus DROP (received)"; }
        "DBL_DQS_DBL_SPAM"              { weight = 6.0; description = "Spamhaus DBL dominio spam"; }
        "DBL_DQS_DBL_PHISH"             { weight = 7.0; description = "Spamhaus DBL dominio phishing"; }
        "DBL_DQS_DBL_MALWARE"           { weight = 8.0; description = "Spamhaus DBL dominio malware"; }
        "DBL_DQS_DBL_ABUSE"             { weight = 5.0; description = "Spamhaus DBL dominio abuso"; }
    }
}

group "phishing" {
    symbols {
        "PHISHED_URL"       { weight = 7.5;  description = "Phishing: anchor difiere del href"; one_shot = true; }
        "PHISHED_OPENPHISH" { weight = 7.5;  description = "URL en feed OpenPhish"; one_shot = true; }
        "PHISHED_STRICT"    { weight = 10.0; description = "Dominio protegido suplantado"; one_shot = true; }
        "REDIRECTOR_FALSE"  { weight = 0.0;  description = "Redirector legítimo conocido"; one_shot = true; }
    }
}
RSGROUPS

cat > /usr/local/etc/rspamd/local.d/fuzzy_check.conf <<RSFUZZY
enabled = false;
RSFUZZY

# Configuración para que el worker HTTP (API) solo escuche en loopback
cat > /usr/local/etc/rspamd/local.d/worker-controller.inc <<RSCTRL
bind_socket = "127.0.0.1:11334";
RSCTRL

# Configuración para el milter worker
cat > /usr/local/etc/rspamd/local.d/worker-proxy.inc <<RSPRXY
bind_socket = "127.0.0.1:11332";
milter = yes;
timeout = 120s;
upstream "local" {
    default = yes;
    self_scan = yes;
}
RSPRXY

# Directorio writable por www para configuración dinámica (Spamhaus DQS, etc.)
mkdir -p /var/sentora/rspamd
chown www:www /var/sentora/rspamd
chmod 750 /var/sentora/rspamd

# local.d/rbl.conf — incluye el fichero dinámico generado por el panel
# (cuando no existe el fichero dinámico, try=true lo ignora silenciosamente)
cat > /usr/local/etc/rspamd/local.d/rbl.conf <<RSRBL
# Sentora: include dinámico para configuración RBL gestionada desde el panel.
# No editar — gestionar desde antispam_admin → Spamhaus DQS.
.include(try=true,priority=10) "/var/sentora/rspamd/rbl.conf"
RSRBL

# local.d/phishing.conf — include dinámico para configuración phishing del panel
cat > /usr/local/etc/rspamd/local.d/phishing.conf <<RSPHISH
# Sentora: include dinámico para phishing gestionado desde el panel.
.include(try=true,priority=10) "/var/sentora/rspamd/phishing.conf"
RSPHISH

# Ficheros dinámicos phishing (www:www)
cat > /var/sentora/rspamd/phishing.conf << 'PHCONF'
# Generado por Sentora antispam_admin — no editar manualmente
openphish_enabled = false;
openphish_map = "https://raw.githubusercontent.com/openphish/public_feed/refs/heads/main/feed.txt";

exceptions {
    REDIRECTOR_FALSE = ["/var/sentora/rspamd/phishing_redirectors.map"];
}

strict_domains {
    PHISHED_STRICT = ["/var/sentora/rspamd/phishing_strict_domains.map"];
}
PHCONF

cat > /var/sentora/rspamd/phishing_redirectors.map << 'PHREDIRS'
t.co
bit.ly
goo.gl
tinyurl.com
ow.ly
buff.ly
dlvr.it
ift.tt
feedburner.com
PHREDIRS

touch /var/sentora/rspamd/phishing_strict_domains.map

chown www:www /var/sentora/rspamd/phishing.conf \
              /var/sentora/rspamd/phishing_redirectors.map \
              /var/sentora/rspamd/phishing_strict_domains.map
chmod 640 /var/sentora/rspamd/phishing.conf \
          /var/sentora/rspamd/phishing_redirectors.map \
          /var/sentora/rspamd/phishing_strict_domains.map

redis-cli HSET sentora:antispam:phishing openphish_enabled 0

sysrc rspamd_enable="YES"
ok "rspamd configurado"

###############################################################################
# 10e-bis. CLAMAV
###############################################################################
info "Instalando y configurando ClamAV..."

pkg install -y -r FreeBSD-ports clamav

# Activar TCPSocket en clamd.conf para que PHP conecte por TCP
CLAMD_CONF=/usr/local/etc/clamd.conf
grep -q '^TCPSocket ' "$CLAMD_CONF" || echo "TCPSocket 3310"    >> "$CLAMD_CONF"
grep -q '^TCPAddr '   "$CLAMD_CONF" || echo "TCPAddr 127.0.0.1" >> "$CLAMD_CONF"
sed -i '' 's/^LocalSocket /#LocalSocket /' "$CLAMD_CONF" 2>/dev/null || true

# freshclam: 4 comprobaciones/día por defecto
FC_CONF=/usr/local/etc/freshclam.conf
grep -q '^Checks ' "$FC_CONF" && \
    sed -i '' 's/^Checks .*/Checks 4/' "$FC_CONF" || \
    echo "Checks 4" >> "$FC_CONF"

# Directorio Sentora para ClamAV (www:www)
mkdir -p /var/sentora/clamav/quarantine
chown -R www:www /var/sentora/clamav
chmod 750 /var/sentora/clamav
chmod 700 /var/sentora/clamav/quarantine

# Fichero dinámico antivirus.conf — vacío (desactivado por defecto)
touch /var/sentora/clamav/antivirus.conf
echo "4"       > /var/sentora/clamav/freshclam_checks.conf
echo "disable" > /var/sentora/clamav/scan_schedule.conf
chown www:www /var/sentora/clamav/antivirus.conf \
              /var/sentora/clamav/freshclam_checks.conf \
              /var/sentora/clamav/scan_schedule.conf
chmod 640 /var/sentora/clamav/antivirus.conf \
          /var/sentora/clamav/freshclam_checks.conf \
          /var/sentora/clamav/scan_schedule.conf

# Fichero estático rspamd (root): include dinámico
cat > /usr/local/etc/rspamd/local.d/antivirus.conf << 'RSAV'
# Sentora: ClamAV vía rspamd — gestionado desde clamav_admin.
.include(try=true,priority=10) "/var/sentora/rspamd/antivirus.conf"
RSAV
# Nota: el dinámico está en /var/sentora/clamav/antivirus.conf
# pero el include lo referenciaremos en /var/sentora/rspamd/ para consistencia
# Crear symlink o copiar — usamos el path directo en el include:
cat > /usr/local/etc/rspamd/local.d/antivirus.conf << 'RSAV'
.include(try=true,priority=10) "/var/sentora/clamav/antivirus.conf"
RSAV

# Scripts privilegiados
cat > /usr/local/sentora/bin/clamav_freshclam_update.sh << 'EOF'
#!/bin/sh
LOG=/var/sentora/clamav/freshclam_update.log
echo "=== Actualización iniciada: $(date) ===" >> "$LOG"
/usr/local/bin/freshclam --quiet >> "$LOG" 2>&1
echo "=== Actualización finalizada: $(date) ===" >> "$LOG"
chmod 644 "$LOG"
EOF

cat > /usr/local/sentora/bin/clamav_scan_mailboxes.sh << 'EOF'
#!/bin/sh
LOG=/var/sentora/clamav/scan_results.log
QUARANTINE=/var/sentora/clamav/quarantine
mkdir -p "$QUARANTINE"
chmod 700 "$QUARANTINE"
echo "=== Escaneo iniciado: $(date) ===" >> "$LOG"
/usr/local/bin/clamdscan --quiet --move="$QUARANTINE" /var/mail >> "$LOG" 2>&1
echo "=== Escaneo finalizado: $(date) ===" >> "$LOG"
tail -1000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"
chmod 644 "$LOG"
EOF

cat > /usr/local/sentora/bin/clamav_cron_update.sh << 'EOF'
#!/bin/sh
SCAN_CONF=/var/sentora/clamav/scan_schedule.conf
CHECKS_CONF=/var/sentora/clamav/freshclam_checks.conf
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
    printf '%s\n%s /usr/local/sentora/bin/clamav_scan_mailboxes.sh\n' \
        "$CTAB" "$SCAN_SCHED" | crontab -u root -
else
    printf '%s\n' "$CTAB" | crontab -u root - 2>/dev/null
fi
EOF

cat > /usr/local/sentora/bin/clamav_scan_launch.sh << 'EOF'
#!/bin/sh
# Lanza clamav_scan_mailboxes.sh como daemon verdadero (FreeBSD daemon(8)).
# Retorna en milisegundos — no bloquea PHP-FPM aunque el escaneo tarde minutos.
LOCK=/var/sentora/clamav/scan.lock
if [ -f "$LOCK" ]; then
    LPID=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$LPID" ] && kill -0 "$LPID" 2>/dev/null; then
        exit 0
    fi
    rm -f "$LOCK"
fi
/usr/sbin/daemon -f -p "$LOCK" /usr/local/sentora/bin/clamav_scan_mailboxes.sh
exit 0
EOF

cat > /usr/local/sentora/bin/clamav_freshclam_launch.sh << 'EOF'
#!/bin/sh
LOCK=/var/sentora/clamav/freshclam.lock
if [ -f "$LOCK" ]; then
    LPID=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$LPID" ] && kill -0 "$LPID" 2>/dev/null; then
        exit 0
    fi
    rm -f "$LOCK"
fi
/usr/sbin/daemon -f -p "$LOCK" /usr/local/sentora/bin/clamav_freshclam_update.sh
exit 0
EOF

cat > /usr/local/sentora/bin/clamav_clamd_stop.sh << 'EOF'
#!/bin/sh
/usr/sbin/daemon -f /usr/sbin/service clamav_clamd stop
exit 0
EOF

chmod 500 /usr/local/sentora/bin/clamav_freshclam_update.sh \
          /usr/local/sentora/bin/clamav_scan_mailboxes.sh \
          /usr/local/sentora/bin/clamav_scan_launch.sh \
          /usr/local/sentora/bin/clamav_freshclam_launch.sh \
          /usr/local/sentora/bin/clamav_clamd_stop.sh \
          /usr/local/sentora/bin/clamav_cron_update.sh

# Redis — estado inicial
redis-cli HSET sentora:clamav email_enabled 0 email_action reject \
               scan_freq disable scan_hour 3 freshclam_checks 4

# Nota: el registro BD de clamav_admin (y clamav_user/antispam/…) está en
# sentora_core.sql — se importa con el resto del esquema, no aquí.

# Descargar firmas (en background para no bloquear el instalador)
freshclam --quiet &

sysrc clamav_clamd_enable="YES"
sysrc clamav_freshclam_enable="YES"
ok "ClamAV configurado (firmas descargándose en background)"

###############################################################################
# 10f. PROFTPD
###############################################################################
info "Configurando ProFTPD..."

PROFTPD_CONF_DIR="/usr/local/etc/sentora/proftpd"
mkdir -p "$PROFTPD_CONF_DIR"

# Config principal (desde preconf, reemplazando placeholders)
sed \
    -e "s|!SQL_PASSWORD!|$PROFTPD_DB_PASS|g" \
    -e "s|!ADMIN_EMAIL!|root@localhost|g" \
    -e "s|!SQL_MIN_ID!|500|g" \
    "$PANEL_CONF/proftpd/proftpd-mysql.conf" \
    > "$PROFTPD_CONF_DIR/proftpd-mysql.conf"

# Incluir TLS y apuntar al config Sentora desde el config global de FreeBSD
cat > /usr/local/etc/proftpd.conf << 'PFEOF'
Include /usr/local/etc/sentora/proftpd/proftpd-mysql.conf
PFEOF

# Generar certificado TLS autofirmado para FTPS
openssl req -x509 -newkey rsa:2048 \
    -keyout "$PROFTPD_CONF_DIR/proftpd.key" \
    -out    "$PROFTPD_CONF_DIR/proftpd.crt" \
    -days   3650 -nodes \
    -subj   "/CN=$(hostname)/O=Sentora FTP/C=ES" 2>/dev/null

chmod 600 "$PROFTPD_CONF_DIR/proftpd.key"
chmod 644 "$PROFTPD_CONF_DIR/proftpd.crt"
chown root:wheel "$PROFTPD_CONF_DIR/proftpd.key" "$PROFTPD_CONF_DIR/proftpd.crt"

# ProFTPD (y Postfix) necesitan que el hostname del sistema resuelva a una IP.
# Por defecto el ServerName de proftpd es el hostname corto; si no está en
# /etc/hosts, proftpd aborta con "no valid servers configured".
_HN=$(hostname)
if ! grep -qw "$_HN" /etc/hosts; then
    printf '%s\t%s %s\n' "$SERVER_IP" "$PANEL_FQDN" "$_HN" >> /etc/hosts
    ok "Hostname $_HN y FQDN $PANEL_FQDN añadidos a /etc/hosts"
fi

sysrc proftpd_enable="YES"
ok "ProFTPD configurado"

###############################################################################
# 10f. PF + SSHGUARD (CORTAFUEGOS)
###############################################################################
info "Configurando pf y SSHGuard..."

# Crear ficheros vacíos de tablas pf (los scripts fw_* los rellenan en tiempo de ejecución)
touch "$PANEL_DATA/run/pf_blocked.txt"
touch "$PANEL_DATA/run/pf_whitelist.txt"
chown root:www "$PANEL_DATA/run/pf_blocked.txt" "$PANEL_DATA/run/pf_whitelist.txt"
chmod 660 "$PANEL_DATA/run/pf_blocked.txt" "$PANEL_DATA/run/pf_whitelist.txt"

# pf.conf — política default-deny + tablas Sentora + anchor para reglas dinámicas
# Si ya existe un pf.conf lo sobreescribimos con la configuración Sentora base.
cat > /etc/pf.conf << PFCF
# /etc/pf.conf — Sentora Firewall (gestionado por fw_admin)
# NO editar manualmente: usar el módulo fw_admin del panel.

# ---- Tablas persistentes ----
table <sentora_whitelist> persist file "$PANEL_DATA/run/pf_whitelist.txt"
table <sentora_blocked>   persist file "$PANEL_DATA/run/pf_blocked.txt"
table <sshguard>          persist

# ---- Política: denegar entrada, permitir salida y tráfico establecido ----
set skip on lo0
block in  all
pass  out all keep state

# ---- ANTI-SPAM: bloqueo de correo SALIENTE DIRECTO de los inquilinos  (NO BORRAR) ----
# Regla "sentora_smtp_egress". QUÉ HACE: impide que los usuarios de HOSTING (uid >= 2001, es
# decir los h_<cliente>) hagan entrega SMTP DIRECTA al puerto 25 de los MX de destino.
# PARA QUÉ: si una cuenta se ve comprometida (webshell, app vulnerable), no puede convertir el
# servidor en un cañón de spam / open-relay saliente (el problema nº1 de abuso en hosting).
# NO rompe el correo legítimo: el Postfix local corre como 'postfix' (uid 125, NO bloqueado) y
# las apps que envían por 127.0.0.1 no se filtran (set skip on lo0). Los puertos 587/465 (relays
# autenticados tipo SendGrid/Gmail) se dejan ABIERTOS a propósito para no romper apps.
# Si quieres permitir a un cliente concreto enviar directo por 25, exclúyelo aquí.
block out quick proto tcp from any to any port 25 user >= 2001

# ---- Excepciones de prioridad alta (antes que cualquier bloqueo) ----
pass  quick from <sentora_whitelist>
block drop quick from <sentora_blocked>
block drop quick from <sshguard>

# ---- Red de seguridad SSH (SIEMPRE, no gestionable desde el panel) ----
# Garantiza acceso remoto pase lo que pase con el anchor: el panel nunca puede
# dejarte sin SSH. El resto de servicios (web, correo, DNS, FTP...) se gestionan
# desde fw_admin (tabla x_fw_rules -> anchor sentora_rules).
pass in quick proto tcp to port 22 keep state

# ---- Reglas gestionadas por el panel (fw_admin) ----
# Se cargan desde fichero para que persistan tras reiniciar; bin/fw_rules_apply.sh
# lo regenera al cambiar reglas y el daemon lo refresca. El instalador crea el
# fichero tras importar la BD, así que siempre existe al cargar pf.
anchor "sentora_rules"
load anchor "sentora_rules" from "$PANEL_DATA/run/pf_custom_rules.txt"
PFCF

# Generar el fichero del anchor desde las reglas por defecto de x_fw_rules (ya
# importadas en la BD). DEBE existir antes de arrancar pf: pf.conf hace
# "load anchor ... from" y fallaría si no existe. touch primero como salvaguarda.
touch "$PANEL_DATA/run/pf_custom_rules.txt"
sh "$PANEL_PATH/bin/fw_rules_apply.sh" 2>/dev/null || true
chown root:www "$PANEL_DATA/run/pf_custom_rules.txt" 2>/dev/null || true
chmod 640 "$PANEL_DATA/run/pf_custom_rules.txt" 2>/dev/null || true

# SSHGuard: integración con pf
cat > /usr/local/etc/sshguard.conf << SGCF
# SSHGuard — Sentora
# BACKEND: ruta REAL del backend pf (el paquete lo instala en /usr/local/libexec, NO en un
# subdirectorio /sshguard/). Una ruta mal aquí -> sshguard nunca arranca ("not executable").
BACKEND="/usr/local/libexec/sshg-fw-pf"

THRESHOLD=40
BLOCK_TIME=120
DETECTION_TIME=1800
WHITELIST_FILE=/usr/local/etc/sshguard.whitelist
SGCF

# Whitelist mínima: localhost nunca se bloquea
cat > /usr/local/etc/sshguard.whitelist << 'SGWL'
127.0.0.1
::1
SGWL

# SSHGuard se ejecuta como SERVICIO (no como pipe de syslog): así el panel (fw_admin) puede
# ver su estado y activarlo/desactivarlo con `service sshguard ...`. Lee el auth log con
# sshguard_watch_logs. (El método pipe de syslog es incompatible con el status/toggle del panel.)
sysrc sshguard_watch_logs="/var/log/auth.log"

sysrc pf_enable="YES"
sysrc pf_rules="/etc/pf.conf"
sysrc sshguard_enable="YES"

ok "pf y SSHGuard configurados"

###############################################################################
# 11. APACHE + PHP-FPM
###############################################################################
info "Configurando Apache..."

HTTP_CONF="/usr/local/etc/apache24/httpd.conf"

# Activar módulos necesarios (descomentar si están comentados)
for modspec in \
    "rewrite_module libexec/apache24/mod_rewrite.so" \
    "ssl_module libexec/apache24/mod_ssl.so" \
    "proxy_module libexec/apache24/mod_proxy.so" \
    "proxy_fcgi_module libexec/apache24/mod_proxy_fcgi.so" \
    "deflate_module libexec/apache24/mod_deflate.so"
do
    modname=$(echo "$modspec" | cut -d' ' -f1)
    modpath=$(echo "$modspec" | cut -d' ' -f2)
    if grep -q "^#LoadModule $modname " "$HTTP_CONF"; then
        sed -i '' "s|^#LoadModule $modname .*|LoadModule $modname $modpath|" "$HTTP_CONF"
    elif ! grep -q "^LoadModule $modname " "$HTTP_CONF"; then
        echo "LoadModule $modname $modpath" >> "$HTTP_CONF"
    fi
done

# KeepAlive
sed -i '' "s|^KeepAlive Off|KeepAlive On|" "$HTTP_CONF"

# Include de Sentora (si no existe ya)
if ! grep -q "Include $PANEL_CONF/apache/httpd.conf" "$HTTP_CONF"; then
    echo "Include $PANEL_CONF/apache/httpd.conf" >> "$HTTP_CONF"
fi

# Copiar apache httpd.conf de preconf (ya copiado a PANEL_CONF en paso 7)
# Asegurar que httpd-vhosts.conf existe vacío para evitar error de Apache
touch "$PANEL_CONF/apache/httpd-vhosts.conf"

# Cert de recuperación del panel (HTTPS). El daemon apache_admin ya genera
# 'Listen 443' + los vhosts SSL del panel (fallback _default_:443 y *:443) usando
# el cert referenciado en la opción panel_ssl_tx: panel/recovery/selfsigned.{crt,key}.
# Si este cert no existe, el config de Apache falla y el daemon revierte al
# placeholder (solo :80) -> Sencrypt avisa "Port 443 CLOSED". Aquí lo generamos.
mkdir -p "$PANEL_CONF/panel/recovery"
if [ ! -f "$PANEL_CONF/panel/recovery/selfsigned.crt" ]; then
    openssl req -x509 -newkey rsa:2048 \
        -keyout "$PANEL_CONF/panel/recovery/selfsigned.key" \
        -out    "$PANEL_CONF/panel/recovery/selfsigned.crt" \
        -days 3650 -nodes -subj "/CN=$PANEL_FQDN/O=Sentora Panel/C=ES" 2>/dev/null
fi
chmod 600 "$PANEL_CONF/panel/recovery/selfsigned.key"
chmod 644 "$PANEL_CONF/panel/recovery/selfsigned.crt"
chown root:wheel "$PANEL_CONF/panel/recovery/selfsigned.key" "$PANEL_CONF/panel/recovery/selfsigned.crt"

sysrc apache24_enable="YES"
ok "Apache configurado"

info "Configurando PHP-FPM..."

PHP_INI="/usr/local/etc/php.ini"
[ -f "$PHP_INI" ] || cp /usr/local/etc/php.ini-production "$PHP_INI"

sed -i '' \
    -e "s|^memory_limit = .*|memory_limit = 256M|" \
    -e "s|^;date.timezone =.*|date.timezone = $TIMEZONE|" \
    -e "s|^date.timezone =.*|date.timezone = $TIMEZONE|" \
    -e "s|^expose_php = On|expose_php = Off|" \
    -e "s|^;session.save_path = .*|session.save_path = \"$PANEL_DATA/sessions\"|" \
    "$PHP_INI"

mkdir -p /var/run/php-fpm
cat > /usr/local/etc/php-fpm.d/www.conf <<PHPFPM
[www]
user  = www
group = www
listen = /var/run/php-fpm/www.sock
listen.owner = www
listen.group = www
pm = dynamic
pm.max_children   = 20
pm.start_servers  = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
php_admin_value[error_log]  = $PANEL_DATA/logs/php_errors.log
php_admin_value[log_errors] = on
php_flag[display_errors] = off
PHPFPM

sysrc php_fpm_enable="YES"
ok "PHP-FPM configurado"

###############################################################################
# 12. BIND
###############################################################################
info "Configurando BIND..."

# Generar rndc.key
rndc-confgen -a -b 256 -c "$PANEL_CONF/bind/rndc.key"
chmod 640 "$PANEL_CONF/bind/rndc.key"
chown bind:bind "$PANEL_CONF/bind/rndc.key"

# rndc.conf
cat > "$PANEL_CONF/bind/rndc.conf" <<RNDCCONF
include "$PANEL_CONF/bind/rndc.key";
options {
    default-key    "rndc-key";
    default-server 127.0.0.1;
    default-port   953;
};
RNDCCONF

# named.conf principal
mkdir -p "$PANEL_CONF/bind/etc"
mkdir -p "$PANEL_CONF/bind/zones"
touch "$PANEL_CONF/bind/etc/named.conf"

cat > "$PANEL_CONF/bind/named.conf" <<NAMEDCF
// Sentora BIND 9 — FreeBSD
// Zonas escritas por daemon en: $PANEL_CONF/bind/etc/named.conf

include "$PANEL_CONF/bind/rndc.key";

controls {
    inet 127.0.0.1 port 953
        allow { 127.0.0.1; }
        keys { "rndc-key"; };
};

acl trusted-servers {
    127.0.0.1;
};

options {
    directory           "$PANEL_DATA/named";
    listen-on  port 53  { any; };
    listen-on-v6        { any; };
    allow-query         { any; };
    recursion           no;

    dump-file              "$PANEL_DATA/named/data/cache_dump.db";
    statistics-file        "$PANEL_DATA/named/data/named_stats.txt";
    memstatistics-file     "$PANEL_DATA/named/data/named_mem_stats.txt";

    bindkeys-file          "/usr/local/etc/namedb/bind.keys";
    managed-keys-directory "$PANEL_DATA/named";
    dnssec-validation   auto;
    auth-nxdomain       no;
    allow-transfer      { none; };
};

logging {
    channel bind_log {
        file "$PANEL_DATA/logs/bind/bind.log"
            versions 3 size 2m;
        severity notice;
        print-time     yes;
        print-category yes;
        print-severity yes;
    };
    channel default_debug {
        file "$PANEL_DATA/logs/bind/debug.log";
        severity dynamic;
    };
    category default { bind_log; };
};

zone "." { type hint; file "/usr/local/etc/namedb/named.root"; };

include "$PANEL_CONF/bind/etc/named.conf";
NAMEDCF

# Copiar zonas base desde preconf
cp -f "$PANEL_PATH/preconf/bind/zones/"* "$PANEL_CONF/bind/zones/" 2>/dev/null || true

# Permisos BIND
chown -R bind:bind "$PANEL_CONF/bind"
chmod    755 "$PANEL_CONF/bind" "$PANEL_CONF/bind/zones" "$PANEL_CONF/bind/etc"
chmod    640 "$PANEL_CONF/bind/rndc.key" "$PANEL_CONF/bind/named.conf"
chmod    644 "$PANEL_CONF/bind/zones/"* 2>/dev/null || true
# www necesita escribir zonas y llamar a rndc — gestionado via doas
chown bind:www "$PANEL_CONF/bind/etc/named.conf" 2>/dev/null || chown bind "$PANEL_CONF/bind/etc/named.conf"
chown -R bind:www "$PANEL_CONF/bind/zones"
chmod 775 "$PANEL_CONF/bind/zones" "$PANEL_CONF/bind/etc"

chown -R bind:bind "$PANEL_DATA/named"
chmod 755 "$PANEL_DATA/named" "$PANEL_DATA/named/data"

sysrc named_enable="YES"
sysrc named_conf="$PANEL_CONF/bind/named.conf"
ok "BIND configurado"

###############################################################################
# 13. ROUNDCUBE
###############################################################################
info "Configurando Roundcube..."

ROUNDCUBE_CONF_DIR="/usr/local/www/roundcube/config"
mkdir -p "$ROUNDCUBE_CONF_DIR"

# config.inc.php — rellenando placeholders
sed \
    -e "s|!ROUNDCUBE_PASSWORD!|$ROUNDCUBE_DB_PASS|g" \
    -e "s|!ROUNDCUBE_DESKEY!|$ROUNDCUBE_DESKEY|g" \
    -e "s|@localhost/sentora_roundcube|@127.0.0.1/sentora_roundcube|g" \
    "$PANEL_CONF/roundcube/roundcube_config.inc.php" \
    > "$ROUNDCUBE_CONF_DIR/config.inc.php"

# sieve plugin
if [ -f "$PANEL_CONF/roundcube/sieve_config.inc.php" ]; then
    cp "$PANEL_CONF/roundcube/sieve_config.inc.php" "$ROUNDCUBE_CONF_DIR/"
fi

chown -R www:www "$ROUNDCUBE_CONF_DIR"
chmod 640 "$ROUNDCUBE_CONF_DIR/config.inc.php"

# Symlink en panel
ln -sfn /usr/local/www/roundcube/public_html "$PANEL_PATH/etc/apps/webmail"
ok "Roundcube configurado"

###############################################################################
# 14. PHPMYADMIN
###############################################################################
info "Configurando phpMyAdmin..."

PMA_CONF_DIR="/usr/local/www/phpMyAdmin"
sed \
    -e "s|!PHPMYADMIN_SECRET!|$PHPMYADMIN_SECRET|g" \
    "$PANEL_CONF/phpmyadmin/config.inc.php" \
    > "$PMA_CONF_DIR/config.inc.php"

chmod 640 "$PMA_CONF_DIR/config.inc.php"
chown root:www "$PMA_CONF_DIR/config.inc.php"

# Symlink en panel
ln -sfn /usr/local/www/phpMyAdmin "$PANEL_PATH/etc/apps/phpmyadmin"
ok "phpMyAdmin configurado"

###############################################################################
# 15. DOAS
###############################################################################
info "Configurando doas..."

cat > /usr/local/etc/doas.conf <<DOASCF
# /usr/local/etc/doas.conf — Sentora privilege::run() rules
# Permite al usuario www (PHP-FPM) ejecutar acciones privilegiadas específicas.
# Cada regla corresponde a una acción en dryden/sys/privilege.class.php.

# ---- Apache ----
permit nopass www as root cmd /usr/local/sbin/apachectl
permit nopass www as root cmd /usr/sbin/service args apache24 reload
permit nopass www as root cmd /usr/sbin/service args apache24 graceful
permit nopass www as root cmd /usr/sbin/service args apache24 restart

# ---- BIND/named ----
permit nopass www as root cmd /usr/sbin/service args named start
permit nopass www as root cmd /usr/sbin/service args named stop
permit nopass www as root cmd /usr/sbin/service args named reload
permit nopass www as root cmd /usr/sbin/service args named restart
permit nopass www as root cmd /usr/local/sbin/rndc

# ---- BIND log permissions (path validado en PHP antes de llamar) ----
permit nopass www as root cmd /bin/chmod
permit nopass www as root cmd /usr/sbin/chown

# ---- Cron (reload tras escribir crontab del panel) ----
permit nopass www as root cmd /usr/sbin/service args cron reload

# ---- PHP-FPM ----
permit nopass www as root cmd /usr/sbin/service args php_fpm reload
permit nopass www as root cmd /usr/sbin/service args php_fpm restart
permit nopass www as root cmd /usr/local/bin/php args -d display_errors=Off $PANEL_PATH/bin/fpm_regen.php

# ---- OpenDKIM (milter-opendkim en FreeBSD) ----
permit nopass www as root cmd /usr/sbin/service args milter-opendkim reload

# ---- ProFTPD ----
permit nopass www as root cmd /usr/sbin/service args proftpd reload
permit nopass www as root cmd /usr/sbin/service args proftpd restart
permit nopass www as root cmd $PANEL_PATH/bin/ftp_cert_generate.sh
permit nopass www as root cmd $PANEL_PATH/bin/ftp_config_update.sh
permit nopass www as root cmd $PANEL_PATH/bin/ftp_cert_paths_update.sh
permit nopass www as root cmd $PANEL_PATH/bin/ftp_cert_upload.sh

# ---- Cortafuegos: pf + SSHGuard (fw_admin) ----
# Los scripts leen IPs desde ficheros en /var/sentora/run/ — nunca por argumento directo.
permit nopass www as root cmd $PANEL_PATH/bin/fw_block_apply.sh
permit nopass www as root cmd $PANEL_PATH/bin/fw_whitelist_apply.sh
permit nopass www as root cmd $PANEL_PATH/bin/fw_sshguard_unban.sh
permit nopass www as root cmd $PANEL_PATH/bin/fw_status_dump.sh
permit nopass www as root cmd $PANEL_PATH/bin/fw_rules_apply.sh
permit nopass www as root cmd $PANEL_PATH/bin/fw_service_toggle.sh
permit nopass www as root cmd /usr/sbin/service args pf reload
permit nopass www as root cmd /usr/sbin/service args sshguard restart

# ---- Hosting users (hosting_users module) ----
# El nombre de usuario va en /var/sentora/run/hosting_useradd_req / hosting_userdel_req
permit nopass www as root cmd $PANEL_PATH/bin/hosting_user_add.sh
permit nopass www as root cmd $PANEL_PATH/bin/hosting_user_del.sh
# Restaura los ficheros del home de una cuenta desde un .zip (backupmgr).
# La orden "USERNAME|/ruta/backup.zip" va en /var/sentora/run/account_restore_req
permit nopass www as root cmd $PANEL_PATH/bin/account_restore.sh

# ---- rspamd (antispam_admin) ----
permit nopass www as root cmd /usr/sbin/service args rspamd start
permit nopass www as root cmd /usr/sbin/service args rspamd stop
permit nopass www as root cmd /usr/sbin/service args rspamd reload
permit nopass www as root cmd /usr/sbin/service args rspamd restart

# ---- ClamAV (clamav_admin) ----
permit nopass www as root cmd /usr/sbin/service args clamav_clamd start
permit nopass www as root cmd /usr/sbin/service args clamav_clamd stop
permit nopass www as root cmd /usr/sbin/service args clamav_clamd restart
permit nopass www as root cmd /usr/sbin/service args clamav_freshclam start
permit nopass www as root cmd /usr/sbin/service args clamav_freshclam restart
permit nopass www as root cmd $PANEL_PATH/bin/clamav_freshclam_update.sh
permit nopass www as root cmd $PANEL_PATH/bin/clamav_scan_mailboxes.sh
permit nopass www as root cmd $PANEL_PATH/bin/clamav_scan_launch.sh
permit nopass www as root cmd $PANEL_PATH/bin/clamav_freshclam_launch.sh
permit nopass www as root cmd $PANEL_PATH/bin/clamav_clamd_stop.sh
permit nopass www as root cmd $PANEL_PATH/bin/clamav_cron_update.sh
DOASCF

chmod 600 /usr/local/etc/doas.conf
ok "doas configurado"

###############################################################################
# 16. CRON — DAEMON DE SENTORA
###############################################################################
info "Instalando cron del daemon de Sentora..."

mkdir -p /etc/cron.d
cat > /etc/cron.d/zdaemon <<CRONEOF
SHELL=/usr/local/bin/bash
PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
MAILTO=root
HOME=/
*/5 * * * * root nice -2 /usr/local/bin/php -q $PANEL_PATH/bin/daemon.php > $PANEL_DATA/logs/daemon-last-run.log 2>&1
CRONEOF

chmod 644 /etc/cron.d/zdaemon
chown root:wheel /etc/cron.d/zdaemon
ok "Cron daemon instalado"

###############################################################################
# 17. NTP — SINCRONIZACIÓN HORARIA
###############################################################################
info "Configurando NTP y zona horaria del sistema..."

# Zona horaria del sistema (tzdata incluido en base de FreeBSD)
if [ -f "/usr/share/zoneinfo/$TIMEZONE" ]; then
    ln -sf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
    ok "Zona horaria del sistema: $TIMEZONE"
else
    warn "Zona horaria '$TIMEZONE' no encontrada en /usr/share/zoneinfo — se usa UTC"
    ln -sf /usr/share/zoneinfo/UTC /etc/localtime
fi

# Sincronización inicial del reloj antes de arrancar servicios
ntpdate -u pool.ntp.org 2>/dev/null \
    || ntpdate -u 0.freebsd.pool.ntp.org 2>/dev/null \
    || warn "ntpdate sin respuesta — comprueba conectividad con NTP"

# Activar ntpd permanentemente (incluido en el sistema base de FreeBSD)
sysrc ntpd_enable="YES"
sysrc ntpd_sync_on_start="YES"   # sync inmediato al arrancar aunque el reloj difiera mucho

ok "NTP configurado"

###############################################################################
# 18. PERMISOS FINALES
###############################################################################
info "Ajustando permisos finales..."

# Panel: solo root puede leer el código; www puede leer (módulos incluidos via PHP)
chown -R root:www "$PANEL_PATH"
find "$PANEL_PATH" -type d -exec chmod 755 {} \;
find "$PANEL_PATH" -type f -exec chmod 644 {} \;
chmod 640 "$PANEL_PATH/cnf/db.php"
# IMPRESCINDIBLE: el 'find -exec chmod 644' de arriba deja los scripts .sh sin +x, y
# privilege::run los ejecuta por doas (execve) -> requieren bit de ejecucion. Sin esto,
# fallan con "Permission denied" (firewall status/enable, clamav, ftp, hosting...).
chmod 0755 "$PANEL_PATH/bin/"*.sh

# etc/tmp: PHP escribe aquí (cachés, etc.)
chown -R www:www "$PANEL_PATH/etc/tmp"
chmod -R 755 "$PANEL_PATH/etc/tmp"

# Mailstore raíz: SGID para que subdirectorios hereden grupo vmail
chown vmail:vmail "$PANEL_DATA/vmail"
chmod 2770 "$PANEL_DATA/vmail"

# Logs dovecot: vmail escribe, www puede leer
chown -R vmail:vmail "$PANEL_DATA/logs/dovecot"
chmod 750 "$PANEL_DATA/logs/dovecot"

# Logs de Apache / panel: www escribe
chown www:www "$PANEL_DATA/logs/sentora.log" \
              "$PANEL_DATA/logs/sentora-access.log" \
              "$PANEL_DATA/logs/sentora-error.log" \
              "$PANEL_DATA/logs/sentora-bandwidth.log" \
              "$PANEL_DATA/logs/php_errors.log" \
              "$PANEL_DATA/logs/roundcube"

# Sesiones PHP
chown www:www "$PANEL_DATA/sessions"
chmod 733 "$PANEL_DATA/sessions"

# Conf sensibles: root:dovecot solo lectura
chmod 640 "$PANEL_CONF/dovecot2/dovecot-mysql.conf" \
          "$PANEL_CONF/dovecot2/dovecot-dict-quota.conf"
chown root:dovecot "$PANEL_CONF/dovecot2/dovecot-mysql.conf" \
                   "$PANEL_CONF/dovecot2/dovecot-dict-quota.conf"

# Mapas postfix: root:postfix solo lectura
chmod 640 "$PANEL_CONF/postfix/mysql-"*.cf
chown root:postfix "$PANEL_CONF/postfix/mysql-"*.cf

ok "Permisos ajustados"

###############################################################################
# 19. INICIAR SERVICIOS
###############################################################################
info "Iniciando servicios..."

service ntpd start 2>/dev/null || true
service mysql-server restart 2>/dev/null || service mysql-server start 2>/dev/null || true
service redis restart 2>/dev/null || service redis start
service rspamd restart 2>/dev/null || service rspamd start
service postfix restart 2>/dev/null || service postfix start
service dovecot restart 2>/dev/null || service dovecot start
service named restart  2>/dev/null || service named start
service milter-opendkim restart 2>/dev/null || service milter-opendkim start 2>/dev/null || true
service proftpd restart 2>/dev/null || service proftpd start 2>/dev/null || true
service pf start 2>/dev/null || true
service sshguard restart 2>/dev/null || service sshguard start 2>/dev/null || true
service syslogd restart 2>/dev/null || true
service apache24 restart 2>/dev/null || service apache24 start
service php_fpm restart 2>/dev/null || service php_fpm start

# ── Cluster DNS: configuración del nodo (primario / secundario) ──
# La API del cluster es dedicada e independiente del kill-switch de la API de usuarios:
# usa su propio flag (dns_cluster_enabled) y token compartido (dns_cluster_token).
if [ "$NODE_ROLE" = "S" ]; then
    info "Uniendo este servidor al cluster DNS como nodo SECUNDARIO..."
    PANEL_SUB=$(printf '%s' "$PANEL_FQDN" | sed "s/\\.${DNS_PROVIDER_DOMAIN}\$//")
    # 1. Obtener la clave TSIG del cluster desde el primario (API dedicada, token de cluster)
    CLUSTER_TSIG=$(curl -sk -m15 -H "Authorization: Bearer $CLUSTER_TOKEN" "$PRIMARY_API_URL/v1/cluster/tsig" \
        | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo isset($j["tsig"])?$j["tsig"]:"";' 2>/dev/null)
    [ -n "$CLUSTER_TSIG" ] || warn "No se pudo obtener la clave TSIG del primario (revisa URL/token del cluster)."
    # 2. Guardar TSIG + token de cluster + activar el cluster; registrar self + primario
    $MYSQL sentora_core -e "
        UPDATE x_settings SET so_value_tx='$CLUSTER_TSIG'  WHERE so_name_vc='dns_tsig_key';
        UPDATE x_settings SET so_value_tx='$CLUSTER_TOKEN' WHERE so_name_vc='dns_cluster_token';
        UPDATE x_settings SET so_value_tx='true'           WHERE so_name_vc='dns_cluster_enabled';
        INSERT IGNORE INTO x_dns_nodes (nd_name_vc,nd_ip_vc,nd_is_self_in,nd_enabled_in,nd_created_ts)
            VALUES ('$PANEL_FQDN','$SERVER_IP',1,1,UNIX_TIMESTAMP());
        INSERT INTO x_dns_nodes (nd_name_vc,nd_ip_vc,nd_api_url_vc,nd_is_self_in,nd_enabled_in,nd_created_ts)
            VALUES ('$PRIMARY_NAME','$PRIMARY_IP','$PRIMARY_API_URL',0,1,UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE nd_ip_vc='$PRIMARY_IP', nd_api_url_vc='$PRIMARY_API_URL', nd_enabled_in=1;
    " 2>/dev/null
    # 3. Registrarse en el primario: crea el peer allí y añade ns/panel del nodo a la zona
    curl -sk -m15 -X POST -H "Authorization: Bearer $CLUSTER_TOKEN" -H "Content-Type: application/json" \
        -d "{\"name\":\"$PANEL_FQDN\",\"ip\":\"$SERVER_IP\",\"api_url\":\"https://$PANEL_FQDN/bin/api.php\",\"panel_host\":\"$PANEL_FQDN\",\"ns_host\":\"$DNS_NS2\"}" \
        "$PRIMARY_API_URL/v1/cluster/nodes" >/dev/null 2>&1 || warn "No se pudo registrar el nodo en el primario."
    ok "Nodo secundario unido al cluster (zona base NO recreada; esclaviza al primario)"
else
    # Nodo PRIMARIO: generar la clave TSIG + el token del cluster, activar el cluster,
    # registrarse como self y crear la zona base (Fase 1).
    _TSIG_OUT=$(tsig-keygen -a hmac-sha256 sentora-cluster 2>/dev/null)
    _TNAME=$(printf '%s' "$_TSIG_OUT" | grep -oE 'key "[^"]+"' | head -1 | sed 's/key "//;s/"//')
    _TSECRET=$(printf '%s' "$_TSIG_OUT" | grep -oE 'secret "[^"]+"' | head -1 | sed 's/secret "//;s/"//')
    CLUSTER_TOKEN=$(openssl rand -hex 32)
    $MYSQL sentora_core -e "
        UPDATE x_settings SET so_value_tx='$_TNAME $_TSECRET' WHERE so_name_vc='dns_tsig_key';
        UPDATE x_settings SET so_value_tx='$CLUSTER_TOKEN'    WHERE so_name_vc='dns_cluster_token';
        UPDATE x_settings SET so_value_tx='true'              WHERE so_name_vc='dns_cluster_enabled';
        INSERT IGNORE INTO x_dns_nodes (nd_name_vc,nd_ip_vc,nd_is_self_in,nd_enabled_in,nd_created_ts)
            VALUES ('$PANEL_FQDN','$SERVER_IP',1,1,UNIX_TIMESTAMP());
    " 2>/dev/null
    php "$PANEL_PATH/bin/create_base_zone.php" > "$PANEL_DATA/logs/base-zone-install.log" 2>&1 || true
    ok "Nodo primario: TSIG + token de cluster generados y zona base creada"
fi

# Generar la config real de Apache y las zonas DNS ejecutando el daemon una vez: el
# vhost del panel con SSL (Listen 443, fallback y :443) lo produce apache_admin, y las
# zonas de BIND las escribe dns_manager, pero solo cuando apache_changed/dns_hasupdates
# = 'true'. Sin esto, hasta el primer cron (5 min) solo estaria el :80 y sin zonas DNS.
mysql -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASS" sentora_core \
    -e "UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed';" 2>/dev/null
php "$PANEL_PATH/bin/daemon.php" > "$PANEL_DATA/logs/daemon-install.log" 2>&1 || true
service apache24 reload 2>/dev/null || true
service named reload 2>/dev/null || service named restart 2>/dev/null || true

ok "Servicios iniciados"

###############################################################################
# 20. RESUMEN FINAL
###############################################################################
echo ""
echo "################################################################"
echo "#         Instalación de Sentora completada                    #"
echo "################################################################"
echo ""
echo "  Panel:     http://$PANEL_FQDN"
echo "  Usuario:   zadmin"
echo "  Contraseña: (la que introdujiste)"
echo ""
echo "  Webmail:   http://$PANEL_FQDN/etc/apps/webmail"
echo "  phpMyAdmin:http://$PANEL_FQDN/etc/apps/phpmyadmin"
echo ""
echo "  Contraseñas guardadas en: $PANEL_DATA/install-passwords.txt"
echo ""
echo "  Próximos pasos:"
echo "  1. Apuntar DNS de $PANEL_FQDN a $SERVER_IP"
echo "  2. Configurar certificado TLS (Let's Encrypt recomendado)"
echo "  3. Establecer myhostname en main.cf como FQDN resolvible"
echo "     para que el correo saliente no sea rechazado"
echo ""
echo "################################################################"
