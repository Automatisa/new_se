#!/bin/sh
# cron_install.sh — instala en la crontab del usuario www el fichero que genera el panel (módulo
# cron) en el staging www-writable. Sin argumentos (ruta fija) para doas. Lo llama el panel vía doas.
# En FreeBSD /var/cron/tabs es root:0700, así que www no puede escribir su crontab directamente;
# `crontab -u www <fichero>` lo instala como root y avisa a cron.
STAGE="/var/bulwark/cron/www.cron"
[ -f "$STAGE" ] || exit 0
crontab -u www "$STAGE"
exit $?
