<?php
// dns_rebuild.php — Reconstrucción SÍNCRONA de zonas DNS + reload de BIND. Reutiliza el hook de
// dns_manager (procesa dns_hasupdates: reescribe las zonas afectadas y named.conf, y recarga BIND).
// Se invoca por doas (acción privilege 'dns_rebuild') para provisionar/limpiar el reto DNS-01 de
// Let's Encrypt AL MOMENTO, sin esperar al ciclo de 5 min del daemon. El llamador debe haber marcado
// dns_hasupdates (insertando/borrando el registro TXT en x_dns) antes de invocar esto.
chdir('/usr/local/bulwark');
require_once 'dryden/loader.inc.php';
require_once 'cnf/db.php';
require_once 'inc/dbc.inc.php';
require_once 'modules/dns_manager/hooks/OnDaemonRun.hook.php';
