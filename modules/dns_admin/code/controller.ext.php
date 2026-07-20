<?php

/**
 * @copyright 2014-2023 Sentora Project (http://www.sentora.org/) 
 * @copyright 2024-present Bulwark / Automatisa (GPLv3 fork of Sentora)
 * Sentora is a GPL fork of the ZPanel Project whose original header follows:
 *
 * ZPanel - A Cross-Platform Open-Source Web Hosting Control panel.
 *
 * @package ZPanel
 * @version $Id$
 * @author Bobby Allen - ballen@bobbyallen.me
 * @copyright (c) 2008-2014 ZPanel Group - http://www.zpanelcp.com/
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License v3
 *
 * This program (ZPanel) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

if (!class_exists('privilege')) {
    require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
}

class module_controller extends ctrl_module
{

    static $ok;
    static $service;
    static $purged;
    static $deleted;
    static $deletedtype;
    static $type;
    static $reset;
    static $addmissing;
    static $logerror   = array();
    static $logwarning = array();
    static $getlog     = array();
    static $showlog;
    static $notwritable;
    static $forceupdate;

    static function getDNSConfig()
    {
        $display = self::DisplayDNSConfig();
        return $display;
    }

    // ── Cluster DNS: lista de nodos con estado; baja/alta confirmadas por el admin ──
    static function getClusterNodes()
    {
        return self::DisplayClusterNodes();
    }

    static function DisplayClusterNodes()
    {
        global $zdbh;
        $nodes = $zdbh->query("SELECT nd_id_pk, nd_name_vc, nd_ip_vc, nd_is_self_in, nd_enabled_in, nd_last_sync_ts
                               FROM x_dns_nodes ORDER BY nd_is_self_in DESC, nd_name_vc")->fetchAll(PDO::FETCH_ASSOC);
        if (!$nodes) {
            return '';  // sin cluster configurado: no mostrar la sección
        }
        $clusterOn = (ctrl_options::GetSystemOption('dns_cluster_enabled') === 'true');
        $STALE     = 1800;   // 30 min sin contacto -> el sistema lo propone como posible baja
        $now       = time();

        $line  = "<hr><h2>" . ui_language::translate("Cluster DNS — Nodos") . "</h2>";
        if (!$clusterOn) {
            $line .= "<div class=\"alert alert-secondary\">" . ui_language::translate("El cluster DNS está desactivado en este nodo (ajuste dns_cluster_enabled).") . "</div>";
        }
        $line .= "<p class=\"text-muted\">" . ui_language::translate("El sistema marca como <b>posible baja</b> los nodos sin contacto reciente. Dar de baja un nodo lo retira del cluster (deja de replicarse y de notificarse) y se propaga al resto; requiere tu confirmación. Se aplica en el próximo ciclo del daemon.") . "</p>";
        $line .= "<form action=\"./?module=dns_admin&action=ClusterNodes\" method=\"post\" style=\"margin:0 0 10px 0\">";
        $line .= runtime_csfr::Token();
        $line .= "<input type=\"hidden\" name=\"inSyncCluster\" value=\"1\">";
        $line .= "<button type=\"submit\" class=\"btn btn-sm btn-primary\"><i class=\"bi bi-arrow-repeat\"></i> " . ui_language::translate("Sincronizar ahora") . "</button>";
        $line .= "</form>";
        $line .= "<table class=\"table table-striped align-middle\"><thead><tr>";
        $line .= "<th>" . ui_language::translate("Nodo") . "</th><th>IP</th><th>" . ui_language::translate("Rol") . "</th><th>" . ui_language::translate("Última sincronización") . "</th><th>" . ui_language::translate("Estado") . "</th><th>" . ui_language::translate("Acción") . "</th>";
        $line .= "</tr></thead><tbody>";

        foreach ($nodes as $n) {
            $id     = (int)$n['nd_id_pk'];
            $name   = htmlspecialchars((string)$n['nd_name_vc'], ENT_QUOTES, 'UTF-8');
            $ip     = htmlspecialchars((string)$n['nd_ip_vc'], ENT_QUOTES, 'UTF-8');
            $self   = ((int)$n['nd_is_self_in'] === 1);
            $isEn   = ((int)$n['nd_enabled_in'] === 1);
            $ts     = $n['nd_last_sync_ts'] ? (int)$n['nd_last_sync_ts'] : 0;
            $ago    = $ts ? self::humanAgo($now - $ts) : ui_language::translate("nunca");
            $stale  = ($ts === 0 || ($now - $ts) > $STALE);

            if ($self) {
                $rol = "<span class=\"badge bg-primary\">" . ui_language::translate("Este servidor") . "</span>";
                $estado = "<span class=\"badge bg-secondary\">—</span>";
                $accion = "";
                $ago    = "—";
            } elseif (!$isEn) {
                $rol = "Peer";
                $estado = "<span class=\"badge bg-dark\">" . ui_language::translate("Dado de baja") . "</span>";
                $accion = self::nodeActionForm($id, (string)$n['nd_name_vc'], 'enable');
            } elseif ($stale) {
                $rol = "Peer";
                $estado = "<span class=\"badge bg-warning text-dark\">&#9888; " . ui_language::translate("Sin contacto") . " (" . $ago . ") — " . ui_language::translate("posible baja") . "</span>";
                $accion = self::nodeActionForm($id, (string)$n['nd_name_vc'], 'disable');
            } else {
                $rol = "Peer";
                $estado = "<span class=\"badge bg-success\">" . ui_language::translate("Activo") . "</span>";
                $accion = self::nodeActionForm($id, (string)$n['nd_name_vc'], 'disable');
            }
            $line .= "<tr><td>" . $name . "</td><td>" . $ip . "</td><td>" . $rol . "</td><td>" . $ago . "</td><td>" . $estado . "</td><td>" . $accion . "</td></tr>";
        }
        $line .= "</tbody></table>";
        return $line;
    }

    private static function nodeActionForm($id, $rawName, $mode)
    {
        // Mensaje de confirmación sin apóstrofos (los hostnames son [a-z0-9.-]).
        if ($mode === 'disable') {
            $field   = 'inDisableNode';
            $confirm = "Confirmas dar de baja el nodo " . $rawName . " del cluster DNS? Dejara de replicarse y se propagara al resto de nodos.";
            $btn     = "<button type=\"submit\" class=\"btn btn-sm btn-outline-danger\"><i class=\"bi bi-x-circle\"></i> " . ui_language::translate("Dar de baja") . "</button>";
        } else {
            $field   = 'inEnableNode';
            $confirm = "Reactivar el nodo " . $rawName . " en el cluster DNS?";
            $btn     = "<button type=\"submit\" class=\"btn btn-sm btn-outline-success\"><i class=\"bi bi-arrow-clockwise\"></i> " . ui_language::translate("Reactivar") . "</button>";
        }
        $onsub = htmlspecialchars("return confirm('" . $confirm . "');", ENT_QUOTES, 'UTF-8');
        $f  = "<form action=\"./?module=dns_admin&action=ClusterNodes\" method=\"post\" style=\"margin:0\" onsubmit=\"" . $onsub . "\">";
        $f .= runtime_csfr::Token();
        $f .= "<input type=\"hidden\" name=\"" . $field . "\" value=\"" . (int)$id . "\">";
        $f .= $btn;
        $f .= "</form>";
        return $f;
    }

    private static function humanAgo($secs)
    {
        $secs = (int)$secs;
        if ($secs < 60)    return $secs . "s";
        if ($secs < 3600)  return (int)floor($secs / 60) . "min";
        if ($secs < 86400) return (int)floor($secs / 3600) . "h";
        return (int)floor($secs / 86400) . "d";
    }

    static function getClusterTls()
    {
        return self::DisplayClusterTls();
    }

    // Lee un certificado PEM (si es legible por el panel) y devuelve datos resumidos, o null.
    private static function certInfo($path)
    {
        if ($path === '' || !@is_readable($path)) return null;
        $pem = @file_get_contents($path);
        if ($pem === false || $pem === '') return null;
        $x = @openssl_x509_parse($pem);
        if (!is_array($x)) return null;
        return [
            'subject' => isset($x['subject']['CN']) ? (string)$x['subject']['CN'] : '',
            'issuer'  => isset($x['issuer']['CN'])  ? (string)$x['issuer']['CN']  : '',
            'to'      => isset($x['validTo_time_t']) ? date('Y-m-d', (int)$x['validTo_time_t']) : '',
            'days'    => isset($x['validTo_time_t']) ? (int)floor(((int)$x['validTo_time_t'] - time()) / 86400) : null,
            'san'     => isset($x['extensions']['subjectAltName']) ? (string)$x['extensions']['subjectAltName'] : '',
            'fp'      => @openssl_x509_fingerprint($pem, 'sha256') ?: '',
        ];
    }

    // Sección: seguridad del canal de control del cluster (TLS entre nodos) + gestión de la CA propia.
    static function DisplayClusterTls()
    {
        global $zdbh;
        if (!(int)$zdbh->query("SELECT COUNT(*) FROM x_dns_nodes")->fetchColumn()) {
            return '';  // sin cluster configurado
        }
        $H = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $mode   = strtolower((string)ctrl_options::GetSystemOption('dns_cluster_tls_verify'));
        if (!in_array($mode, ['off', 'pin', 'ca'], true)) $mode = 'off';
        $caFile = (string)ctrl_options::GetSystemOption('dns_cluster_ca_file');

        $line  = "<hr><h2>" . ui_language::translate("Cluster DNS — Seguridad del canal entre nodos (TLS)") . "</h2>";
        $line .= "<p class=\"text-muted\">" . ui_language::translate("Cómo verifica este nodo el certificado de sus peers al sincronizar la API del cluster. El AXFR de zonas va aparte, firmado con TSIG.") . "</p>";

        // --- Formulario: modo + fichero CA ---
        $sel = function ($v, $cur) { return $v === $cur ? " selected" : ""; };
        $line .= "<form action=\"./?module=dns_admin&action=ClusterTls\" method=\"post\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $line .= "<tr><th style=\"width:260px\">" . ui_language::translate("Verificación TLS") . "</th><td>";
        $line .= "<select name=\"inTlsMode\" class=\"form-select\" style=\"max-width:320px\">";
        $line .= "<option value=\"off\"" . $sel('off', $mode) . ">off — " . ui_language::translate("sin verificar (dev / LAN de confianza)") . "</option>";
        $line .= "<option value=\"pin\"" . $sel('pin', $mode) . ">pin — " . ui_language::translate("fija la clave del peer (autofirmado, corta MITM)") . "</option>";
        $line .= "<option value=\"ca\""  . $sel('ca',  $mode) . ">ca — "  . ui_language::translate("CA propia (verificación fuerte por IP)") . "</option>";
        $line .= "</select></td><td>" . ui_language::translate("Producción sin certificados públicos: usa <b>ca</b> con la CA propia; <b>pin</b> es una alternativa ligera.") . "</td></tr>";
        $line .= "<tr><th>" . ui_language::translate("Fichero CA (modo ca)") . "</th><td><input type=\"text\" name=\"inCaFile\" class=\"form-control\" style=\"max-width:520px\" value=\"" . $H($caFile) . "\" placeholder=\"/usr/local/etc/bulwark/cluster-ca/ca.crt\"></td><td>" . ui_language::translate("Ruta al bundle PEM de la CA del cluster (legible por el panel). Vacío = almacén del sistema.") . "</td></tr>";
        $line .= "<tr><th></th><td colspan=\"2\"><button class=\"button-loader btn btn-primary\" type=\"submit\" name=\"inSaveClusterTls\" value=\"1\"><i class=\"bi bi-floppy me-1\"></i>" . ui_language::translate("Guardar") . "</button></td></tr>";
        $line .= "</table></form>";

        // --- Estado: CA + cert de este nodo ---
        $ca = self::certInfo($caFile);
        $panelCrt = (string)ctrl_options::GetSystemOption('panel_ssl_crt');
        if ($panelCrt === '') $panelCrt = '/usr/local/etc/bulwark/panel/recovery/selfsigned.crt';
        $node = self::certInfo($panelCrt);

        $line .= "<table class=\"table\"><thead><tr><th style=\"width:200px\">" . ui_language::translate("Elemento") . "</th><th>" . ui_language::translate("Detalle") . "</th></tr></thead><tbody>";
        // CA
        if ($ca) {
            $badge = ($ca['days'] !== null && $ca['days'] < 0)
                ? "<span class=\"badge bg-danger\">" . ui_language::translate("CADUCADA") . "</span>"
                : (($ca['days'] !== null && $ca['days'] < 30) ? "<span class=\"badge bg-warning text-dark\">" . ui_language::translate("caduca pronto") . "</span>" : "<span class=\"badge bg-success\">OK</span>");
            $line .= "<tr><td><b>" . ui_language::translate("CA del cluster") . "</b></td><td>" . $badge
                   . " " . $H($ca['subject']) . " — " . ui_language::translate("caduca") . " " . $H($ca['to'])
                   . " (" . (int)$ca['days'] . "d)<br><small class=\"text-muted\">SHA-256: " . $H($ca['fp']) . "</small></td></tr>";
        } else {
            $line .= "<tr><td><b>" . ui_language::translate("CA del cluster") . "</b></td><td><span class=\"badge bg-secondary\">" . ui_language::translate("no disponible / no legible") . "</span> " . ui_language::translate("Créala por CLI (ver abajo) y pon su ruta arriba.") . "</td></tr>";
        }
        // Cert del nodo
        if ($node) {
            $sanNode = $node['san'] !== '' ? " — SAN: " . $H($node['san']) : "";
            $badge = ($node['days'] !== null && $node['days'] < 0)
                ? "<span class=\"badge bg-danger\">" . ui_language::translate("CADUCADO") . "</span>"
                : (($node['days'] !== null && $node['days'] < 30) ? "<span class=\"badge bg-warning text-dark\">" . ui_language::translate("caduca pronto") . "</span>" : "<span class=\"badge bg-success\">OK</span>");
            $line .= "<tr><td><b>" . ui_language::translate("Certificado de este nodo") . "</b></td><td>" . $badge
                   . " " . ui_language::translate("emisor") . ": " . $H($node['issuer']) . " — " . ui_language::translate("caduca") . " " . $H($node['to']) . $sanNode . "</td></tr>";
        } else {
            $line .= "<tr><td><b>" . ui_language::translate("Certificado de este nodo") . "</b></td><td><span class=\"badge bg-secondary\">" . ui_language::translate("no legible por el panel") . "</span></td></tr>";
        }
        $line .= "</tbody></table>";

        // --- Pins (modo pin) ---
        if ($mode === 'pin') {
            $peers = $zdbh->query("SELECT nd_id_pk, nd_name_vc, nd_cert_pin_vc FROM x_dns_nodes WHERE nd_is_self_in=0 ORDER BY nd_name_vc")->fetchAll(PDO::FETCH_ASSOC);
            $line .= "<h3>" . ui_language::translate("Huellas fijadas (pin) de los peers") . "</h3>";
            $line .= "<table class=\"table table-striped\"><thead><tr><th>" . ui_language::translate("Peer") . "</th><th>" . ui_language::translate("Huella (SHA-256 SPKI)") . "</th><th></th></tr></thead><tbody>";
            foreach ($peers as $p) {
                $pin = (string)$p['nd_cert_pin_vc'];
                $shown = $pin !== '' ? $H(substr($pin, 0, 26)) . "…" : "<span class=\"text-muted\">" . ui_language::translate("(se captura en la próxima sync)") . "</span>";
                $reset = "<form action=\"./?module=dns_admin&action=ClusterTls\" method=\"post\" style=\"margin:0\" onsubmit=\"return confirm('Reiniciar la huella de este peer? Se recapturará (TOFU) en la próxima sincronización.');\">" . runtime_csfr::Token()
                       . "<input type=\"hidden\" name=\"inResetPin\" value=\"" . (int)$p['nd_id_pk'] . "\"><button class=\"btn btn-sm btn-outline-secondary\" type=\"submit\"><i class=\"bi bi-arrow-repeat\"></i> " . ui_language::translate("Reiniciar") . "</button></form>";
                $line .= "<tr><td>" . $H($p['nd_name_vc']) . "</td><td><code>" . $shown . "</code></td><td>" . ($pin !== '' ? $reset : '') . "</td></tr>";
            }
            $line .= "</tbody></table>";
        }

        // --- Guía CLI para crear/renovar la CA (por seguridad NO se genera desde la web) ---
        $self = $zdbh->query("SELECT nd_ip_vc, nd_name_vc FROM x_dns_nodes WHERE nd_is_self_in=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $sip  = $self ? $H($self['nd_ip_vc']) : "&lt;ip&gt;";
        $sfq  = $self ? $H($self['nd_name_vc']) : "&lt;fqdn&gt;";
        $line .= "<div class=\"alert alert-info\"><b>" . ui_language::translate("Ciclo de vida de la CA (por CLI, como root)") . "</b><br>";
        $line .= "<small>" . ui_language::translate("La clave privada de la CA NO se genera ni se guarda desde el panel (el usuario web no debe custodiarla). Se crea y renueva por CLI en el nodo emisor y su clave no sale de ahí.") . "</small>";
        $line .= "<pre style=\"margin:8px 0 0 0\">"
               . "# 1) Crear la CA (una vez, en el nodo emisor)\n"
               . "/usr/local/bulwark/bin/dns_cluster_ca.sh init\n\n"
               . "# 2) Emitir el cert de este nodo (IP en el SAN) y aplicarlo a Apache\n"
               . "/usr/local/bulwark/bin/dns_cluster_ca.sh issue " . $sip . " " . $sfq . "\n"
               . "/usr/local/bulwark/bin/dns_cluster_ca.sh apply " . $sip . "\n\n"
               . "# 3) Copiar ca.crt a cada nodo y poner su ruta arriba; activar el modo 'ca'\n"
               . "# Renovar: repetir 'issue'/'apply' cuando el cert (o la CA) esté por caducar."
               . "</pre></div>";

        return $line;
    }

    static function DisplayDNSConfig()
    {
        global $zdbh;
        global $controller;

        $line = "<style>.active {color: #333;}</style>";
        $line .= "<h2>" . ui_language::translate("Configure your DNS Settings") . "</h2>";
        $line .= "<div style=\"display: block; margin-right:20px;\">";
        $line .= "<div class=\"ui-tabs ui-widget ui-widget-content ui-corner-all\" id=\"dnsTabs\">";
        $line .= "<ul class=\"domains nav nav-tabs\">";
        $line .= "<li class=\"nav-item\"><a class=\"nav-link active\" href=\"#general\" data-bs-toggle=\"tab\">" . ui_language::translate("General") . "</a></li>";
        $line .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"#tools\" data-bs-toggle=\"tab\">" . ui_language::translate("Tools") . "</a></li>";
        $line .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"#services\" data-bs-toggle=\"tab\">" . ui_language::translate("Services") . "</a></li>";
        $line .= "<li class=\"nav-item\"><a class=\"nav-link\" href=\"#logs\" data-bs-toggle=\"tab\">" . ui_language::translate("Logs") . "</a></li>";
        $line .= "</ul>";

        //Tabs Panel Wrap
        $line .= '<div class="tab-content">';

        //general
        $line .= "<div class=\"tab-pane active show\" id=\"general\">";
        $line .= "<form action=\"./?module=dns_admin&action=UpdateDNSConfig\" method=\"post\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $count = 0;
        $sql = "SELECT COUNT(*) FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true'";
        $numrows = $zdbh->prepare($sql);
        $GetModuleName = ui_module::GetModuleName();
        $numrows->bindParam(':moduleName', $GetModuleName);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true' ORDER BY so_cleanname_vc");
                $GetModuleName = ui_module::GetModuleName();
                $sql->bindParam(':moduleName', $GetModuleName);
                $sql->execute();

                while ($row = $sql->fetch()) {
                    $count++;
                    if (ctrl_options::CheckForPredefinedOptions($row['so_defvalues_tx'])) {
                        $fieldhtml = ctrl_options::OuputSettingMenuField($row['so_name_vc'], $row['so_defvalues_tx'], $row['so_value_tx']);
                    } else {
                        $fieldhtml = ctrl_options::OutputSettingTextArea($row['so_name_vc'], $row['so_value_tx']);
                    }
                    $line .= "<tr valign=\"top\"><th nowrap=\"nowrap\">" . ui_language::translate($row['so_cleanname_vc']) . "</th><td>" . $fieldhtml . "</td><td>" . ui_language::translate($row['so_desc_tx']) . "</td></tr>";
                }
                $line .= "<tr><th colspan=\"3\"><button class=\"button-loader btn btn-primary\" type=\"submit\" id=\"button\" name=\"inSaveSystem\"><i class=\"bi bi-floppy me-1\"></i>" . ui_language::translate("Save Changes") . "</button>  <button class=\"button-loader btn btn-secondary\" type=\"button\" onclick=\"window.location.href='./?module=moduleadmin';return false;\"><i class=\"bi bi-x-circle me-1\"></i>" . ui_language::translate("Cancel") . "</button></tr>";
            }
        }
        $line .= "</table>";
        $line .= "</form>";
        $line .= "</div>";
        //tools
        $line .= "<div class=\"tab-pane\" id=\"tools\">";
        $line .= "<form action=\"./?module=dns_admin&action=UpdateTools\" method=\"post\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Reset all Records to Default") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-warning\" type=\"submit\" id=\"button\" name=\"inResetAll\" value=\"1\"><i class=\"bi bi-arrow-counterclockwise me-1\"></i>" . ui_language::translate("Reset") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Reset Records to Default on Single Domain") . " ";
        $line .= "<select name=\"inResetDomainID\">";
        $line .= "<option value=\"\">--- " . ui_language::translate("Select Domain") . " ---</option>";
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $line .= " <option value=\"" . $row['vh_id_pk'] . "\">" . $row['vh_name_vc'] . "</option>";
                }
            }
        }
        $line .= "</select>";
        $line .= "</th>";
        $line .= "<td><button class=\"button-loader btn btn-warning\" type=\"submit\" id=\"button\" name=\"inResetDomain\" value=\"1\"><i class=\"bi bi-arrow-counterclockwise me-1\"></i>" . ui_language::translate("Reset") . "</button></td>";
        $line .= "</tr>";
        $line .= "<th>" . ui_language::translate("Add Default Records to Missing Domains") . "";
        $line .= "</th>";
        $line .= "<td><button class=\"button-loader btn btn-primary\" type=\"submit\" id=\"button\" name=\"inAddMissing\" value=\"1\"><i class=\"bi bi-plus-circle me-1\"></i>" . ui_language::translate("Add") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Delete Record Type from ALL Records") . " ";
        $line .= "<select name=\"inType\" id=\"inType\">";
        $line .= "<option value=\"A\">A</option>";
        $line .= "<option value=\"AAAA\">AAAA</option>";
        $line .= "<option value=\"CNAME\">CNAME</option>";
        $line .= "<option value=\"MX\">MX</option>";
        $line .= "<option value=\"TXT\">TXT</option>";
        $line .= "<option value=\"SRV\">SRV</option>";
        $line .= "<option value=\"SPF\">SPF</option>";
        $line .= "<option value=\"NS\">NS</option>";
        $line .= "</select>";
        $line .= "</th>";
        $line .= "<td><button class=\"button-loader btn btn-danger\" type=\"submit\" id=\"button\" name=\"inDeleteType\" value=\"1\"><i class=\"bi bi-trash me-1\"></i>" . ui_language::translate("Delete") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Purge Deleted Zone Records From Database") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-danger\" type=\"submit\" id=\"button\" name=\"inPurge\" value=\"1\"><i class=\"bi bi-eraser me-1\"></i>" . ui_language::translate("Purge") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Delete ALL Zone Records") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-danger\" type=\"submit\" id=\"button\" name=\"inDeleteAll\" value=\"1\"><i class=\"bi bi-trash me-1\"></i>" . ui_language::translate("Delete All") . "</button></td>";
        $line .= "</tr>";
        $line .= "<th>" . ui_language::translate("Force Records Update on Next Daemon Run") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-primary\" type=\"submit\" id=\"button\" name=\"inForceUpdate\" value=\"1\"><i class=\"bi bi-arrow-repeat me-1\"></i>" . ui_language::translate("Force Update") . "</button></td>";
        $line .= "</tr>";
        $line .= "</table>";
        $line .= "</form>";
        $line .= "</div>";
        //Services
        $line .= "<div class=\"tab-pane\" id=\"services\">";
        $line .= "<form action=\"./?module=dns_admin&action=UpdateService\" method=\"post\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Start Service") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-success\" type=\"submit\" id=\"button\" name=\"inStartService\" value=\"1\"><i class=\"bi bi-play-fill me-1\"></i>" . ui_language::translate("Start") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Stop Service") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-warning\" type=\"submit\" id=\"button\" name=\"inStopService\" value=\"1\"><i class=\"bi bi-stop-fill me-1\"></i>" . ui_language::translate("Stop") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Reload BIND") . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-primary\" type=\"submit\" id=\"button\" name=\"inReloadService\" value=\"1\"><i class=\"bi bi-arrow-repeat me-1\"></i>" . ui_language::translate("Reload") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Service Port Status") . "</th>";
        if (fs_director::CheckForEmptyValue(sys_monitoring::PortStatus(53))) {
            $line .= "<td><span class=\"text-danger fw-semibold\"><i class=\"bi bi-x-circle-fill me-1\"></i>" . ui_language::translate("STOPPED") . "</span></td>";
        } else {
            $line .= "<td><span class=\"text-success fw-semibold\"><i class=\"bi bi-check-circle-fill me-1\"></i>" . ui_language::translate("RUNNING") . "</span></td>";
        }
        $line .= "</tr>";
        $line .= "</table>";
        $line .= "</form>";
        $line .= "</div>";
        //logs
        self::ViewErrors();

        $line .= "<div class=\"tab-pane\" id=\"logs\">";
        $line .= "<form action=\"./?module=dns_admin&action=Updatelogs\" method=\"post\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $line .= "<tr>";
        $line .= "<th style=\"width:350px;\">" . self::CheckLogReadable(ctrl_options::GetSystemOption('bind_log')) . " " . self::CheckLogWritable(ctrl_options::GetSystemOption('bind_log')) . "</th>";
        $line .= "<td><button class=\"button-loader btn btn-primary\" type=\"submit\" id=\"button\" name=\"inSetPerms\" value=\"1\"><i class=\"bi bi-key me-1\"></i>" . ui_language::translate("Set Permissions") . "</button></td>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Clear errors") . "</th>";
        $line .= "<td><button class=\"delete btn btn-danger\" type=\"submit\" id=\"button\" name=\"inClearErrors\" value=\"1\"><i class=\"bi bi-x-circle me-1\"></i>" . ui_language::translate("Clear") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Clear warnings") . "";
        $line .= "</th>";
        $line .= "<td><button class=\"delete btn btn-danger\" type=\"submit\" id=\"button\" name=\"inClearWarnings\" value=\"1\"><i class=\"bi bi-x-circle me-1\"></i>" . ui_language::translate("Clear") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("Clear logs") . "";
        $line .= "</th>";
        $line .= "<td><button class=\"delete btn btn-danger\" type=\"submit\" id=\"button\" name=\"inClearLogs\" value=\"1\"><i class=\"bi bi-x-circle me-1\"></i>" . ui_language::translate("Clear") . "</button></td>";
        $line .= "</tr>";
        $line .= "</table>";
        $line .= "</form>";
        $line .= "<form name=\"launchbindlog\" action=\"modules/dns_admin/code/getbindlog.php\" target=\"bindlogwindow\" method=\"post\" onsubmit=\"window.open('', 'bindlogwindow', 'scrollbars=yes,menubar=no,height=525,width=825,resizable=no,toolbar=no,location=no,status=no')\">";
        $line .= runtime_csfr::Token();
        $line .= "<table class=\"table table-striped\">";
        $line .= "<tr>";
        if (count(self::$logerror) > 0) {
            $logerrorcolor = "red";
        } else {
            $logerrorcolor = NULL;
        }
        $line .= "<th style=\"width:350px;\">" . ui_language::translate("View Errors") . " (<font color=\"" . $logerrorcolor . "\">" . count(self::$logerror) . "</font>)</th>";
        $line .= "<td><button class=\"btn btn-primary\" type=\"submit\" id=\"logerror_a\" name=\"inViewErrors\" value=\"1\"><i class=\"bi bi-eye me-1\"></i>" . ui_language::translate("View") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        if (count(self::$logwarning) > 0) {
            $logwarningcolor = "red";
        } else {
            $logwarningcolor = NULL;
        }
        $line .= "<th>" . ui_language::translate("View warnings") . " (<font color=\"" . $logwarningcolor . "\">" . count(self::$logwarning) . "</font>)</th>";
        $line .= "<td><button class=\"btn btn-primary\" type=\"submit\" id=\"logwarning_a\" name=\"inViewWarnings\" value=\"1\"><i class=\"bi bi-eye me-1\"></i>" . ui_language::translate("View") . "</button></td>";
        $line .= "</tr>";
        $line .= "<tr>";
        $line .= "<th>" . ui_language::translate("View logs") . " (" . count(self::$getlog) . ")</th>";
        $line .= "<td><input type=\"hidden\" name=\"inBindLog\" value=\"" . ctrl_options::GetSystemOption('bind_log') . "\" /><button class=\"btn btn-primary\" type=\"submit\" id=\"button\" name=\"inViewLogs\" value=\"1\"><i class=\"bi bi-eye me-1\"></i>" . ui_language::translate("View") . "</button></td>";
        $line .= "</tr>";
        $line .= "</table>";
        $line .= "</form>";
        $line .= "</div>";



        $line .= "</div>";
        $line .= "</div>";
        $line .= "</div>";

        //CHARTS
        $line .= "<table class=\"none\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td>";
        $line .= self::DisplayDNSUsagepChart();
        $line .= "</td><td>";
        $line .= self::DisplayRecordsUsagepChart();
        $line .= "</td></tr></table>";

        return $line;
    }

    static function doUpdateDNSConfig()
    {
        runtime_csfr::Protect();
        global $zdbh;
        global $controller;
        $sql = "SELECT COUNT(*) FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true'";
        $numrows = $zdbh->prepare($sql);
        $GetModuleName = ui_module::GetModuleName();
        $numrows->bindParam(':moduleName', $GetModuleName);

        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_module_vc=:moduleName AND so_usereditable_en = 'true'");
                $GetModuleName = ui_module::GetModuleName();
                $sql->bindParam(':moduleName', $GetModuleName);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', $row['so_name_vc']))) {
                        $name = $controller->GetControllerRequest('FORM', $row['so_name_vc']);
                        $name2 = $row['so_name_vc'];
                        $updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :name WHERE so_name_vc = :name2");
                        $updatesql->bindParam(':name', $name);
                        $updatesql->bindParam(':name2', $name2);
                        $updatesql->execute();
                        self::TriggerDNSUpdate("0");
                    }
                }
            }
        }
    }

 static function doUpdateService()
    {
        runtime_csfr::Protect();
        global $zdbh;
        global $controller;
        $messages = array();
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inStartService'))) {
            $r = self::StartBind();
            $messages[] = "Start BIND: " . $r;
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inStopService'))) {
            $r = self::StopBind();
            $messages[] = "Stop BIND: " . $r;
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inReloadService'))) {
            $r = self::ReloadBind();
            $messages[] = "Reload BIND: " . $r;
        }
        if (!empty($messages)) {
            self::$service = implode(" | ", $messages);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['zpanel_service_msg'] = self::$service;
            }
        }
    }

    static function doUpdateTools()
    {
        runtime_csfr::Protect();
        global $zdbh;
        global $controller;
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetAll'))) {
            self::ResetAll();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetDomain')) && !fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inResetDomainID'))) {
            self::ResetDomain($controller->GetControllerRequest('FORM', 'inResetDomainID'));
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inAddMissing'))) {
            self::AddMissing();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDeleteType'))) {
            self::DeleteType();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inPurge'))) {
            self::Purge();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inDeleteAll'))) {
            self::DeleteAll();
            self::TriggerDNSUpdate("0");
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inForceUpdate'))) {
            self::$forceupdate = true;
            self::TriggerDNSUpdate("0");
        }
    }

    // Baja/alta de un nodo del cluster, confirmada por el admin desde la lista de servidores.
    static function doClusterNodes()
    {
        runtime_csfr::Protect();
        global $zdbh, $controller;

        // Forzar sincronización ahora: malla de nodos + lista de zonas de los peers.
        // (Corre como bulwark: solo curl + BD; named.conf se regenera en el próximo ciclo del
        //  daemon, que corre como root y recarga BIND.)
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inSyncCluster'))) {
            if (!class_exists('dns_cluster')) {
                require_once ctrl_options::GetSystemOption('bulwark_root') . 'dryden/sys/dns_cluster.class.php';
            }
            dns_cluster::SyncClusterNodes();
            dns_cluster::SyncRemoteZones();
            self::markDnsClusterUpdate();
            self::logCluster("Sincronización manual del cluster forzada desde el panel");
        }

        $disable = $controller->GetControllerRequest('FORM', 'inDisableNode');
        $enable  = $controller->GetControllerRequest('FORM', 'inEnableNode');

        if (!fs_director::CheckForEmptyValue($disable)) {
            $id  = (int)$disable;
            $row = $zdbh->prepare("SELECT nd_name_vc, nd_is_self_in FROM x_dns_nodes WHERE nd_id_pk=:id");
            $row->execute([':id' => $id]);
            $node = $row->fetch();
            // Nunca dar de baja el propio nodo (self).
            if ($node && (int)$node['nd_is_self_in'] === 0) {
                $zdbh->prepare("UPDATE x_dns_nodes SET nd_enabled_in=0 WHERE nd_id_pk=:id")->execute([':id' => $id]);
                $zdbh->prepare("DELETE FROM x_dns_remote_zones WHERE rz_node_fk=:id")->execute([':id' => $id]);
                self::markDnsClusterUpdate();
                self::logCluster("Nodo dado de baja desde el panel: " . $node['nd_name_vc']);
            }
        }
        if (!fs_director::CheckForEmptyValue($enable)) {
            $id  = (int)$enable;
            $row = $zdbh->prepare("SELECT nd_name_vc FROM x_dns_nodes WHERE nd_id_pk=:id AND nd_is_self_in=0");
            $row->execute([':id' => $id]);
            $node = $row->fetch();
            if ($node) {
                $zdbh->prepare("UPDATE x_dns_nodes SET nd_enabled_in=1 WHERE nd_id_pk=:id AND nd_is_self_in=0")->execute([':id' => $id]);
                self::markDnsClusterUpdate();
                self::logCluster("Nodo reactivado desde el panel: " . $node['nd_name_vc']);
            }
        }
    }

    static function doClusterTls()
    {
        runtime_csfr::Protect();
        global $zdbh, $controller;

        // Guardar modo de verificación TLS + ruta del fichero CA.
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inSaveClusterTls'))) {
            $mode = strtolower((string)$controller->GetControllerRequest('FORM', 'inTlsMode'));
            if (!in_array($mode, ['off', 'pin', 'ca'], true)) $mode = 'off';
            $caFile = trim((string)$controller->GetControllerRequest('FORM', 'inCaFile'));
            // Sanear ruta: absoluta y con caracteres de ruta razonables; vacío permitido (almacén del sistema).
            if ($caFile !== '' && !preg_match('#^/[A-Za-z0-9._/\-]+$#', $caFile)) {
                $caFile = (string)ctrl_options::GetSystemOption('dns_cluster_ca_file');
            }
            $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_cluster_tls_verify'")->execute([':v' => $mode]);
            $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_cluster_ca_file'")->execute([':v' => $caFile]);
            self::logCluster("TLS del cluster: modo=" . $mode . " ca_file=" . $caFile);
        }

        // Reiniciar la huella (pin) de un peer -> se recaptura por TOFU en la próxima sync.
        $reset = $controller->GetControllerRequest('FORM', 'inResetPin');
        if (!fs_director::CheckForEmptyValue($reset)) {
            $id = (int)$reset;
            $r  = $zdbh->prepare("SELECT nd_name_vc FROM x_dns_nodes WHERE nd_id_pk=:id AND nd_is_self_in=0");
            $r->execute([':id' => $id]);
            if ($row = $r->fetch()) {
                $zdbh->prepare("UPDATE x_dns_nodes SET nd_cert_pin_vc=NULL WHERE nd_id_pk=:id")->execute([':id' => $id]);
                self::logCluster("Pin TLS reiniciado (re-TOFU) para el peer: " . $row['nd_name_vc']);
            }
        }
    }

    // Auditoría de acciones del cluster desde el panel, en x_logs (con el usuario que actúa).
    private static function logCluster($detail)
    {
        global $zdbh;
        $uid = (int)(isset($_SESSION['zpuid']) ? $_SESSION['zpuid'] : 0);
        try {
            $zdbh->prepare("INSERT INTO x_logs (lg_user_fk, lg_code_vc, lg_module_vc, lg_detail_tx) VALUES (:u, 'CLUSTER', 'dns_admin', :d)")
                 ->execute([':u' => $uid, ':d' => (string)$detail]);
        } catch (Exception $e) {
            // la auditoría no debe abortar la acción
        }
    }

    private static function markDnsClusterUpdate()
    {
        global $zdbh;
        // Marcar regeneración de named.conf sin pisar ids de dominio pendientes.
        $cur = (string)ctrl_options::GetSystemOption('dns_hasupdates');
        if (trim($cur) === '') {
            $zdbh->exec("UPDATE x_settings SET so_value_tx='cluster' WHERE so_name_vc='dns_hasupdates'");
        }
    }

    static function doUpdateLogs()
    {
        runtime_csfr::Protect();
        global $zdbh;
        global $controller;
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inSetPerms'))) {
            self::SetPerms();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearErrors'))) {
            self::ClearErrors();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearWarnings'))) {
            self::ClearWarnings();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inClearLogs'))) {
            self::ClearLog();
        }
        if (!fs_director::CheckForEmptyValue($controller->GetControllerRequest('FORM', 'inViewLogs'))) {
            //self::ViewLogs();
            self::$showlog = TRUE;
        }
    }

   static function StartBind()
    {
        list($code, $stdout, $stderr) = privilege::run('bind_start');
        sleep(2);
        if ($code === 0) {
            return "OK (exit 0)";
        }
        $msg = trim($stderr) != '' ? trim($stderr) : trim($stdout);
        if ($msg == '') { $msg = "exit " . $code; }
        return "FAIL (" . $msg . ")";
    }

   static function StopBind()
    {
        list($code, $stdout, $stderr) = privilege::run('bind_stop');
        sleep(2);
        if ($code === 0) {
            return "OK (exit 0)";
        }
        $msg = trim($stderr) != '' ? trim($stderr) : trim($stdout);
        if ($msg == '') { $msg = "exit " . $code; }
        return "FAIL (" . $msg . ")";
    }

    static function ReloadBind()
    {
        list($code, $stdout, $stderr) = privilege::run('bind_reload');
        if ($code === 0) {
            return "OK (exit 0)";
        }
        $msg = trim($stderr) != '' ? trim($stderr) : trim($stdout);
        if ($msg == '') { $msg = "exit " . $code; }
        return "FAIL (" . $msg . ")";
    }

    static function ResetAll()
    {
        global $zdbh;
        global $controller;
        $vhosts = array();
        $numrecords = 0;
        //Get a list of current domains with records
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL GROUP BY dn_vhost_fk");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $vhosts[] = $row['dn_vhost_fk'];
                    $numrecords++;
                }
            }
        }
        self::$reset = $numrecords;
        //Delete current records
        self::DeleteAll();
        //Create Default Records
        foreach ($vhosts as $vhost) {
            self::CreateDefaultRecords($vhost);
        }
    }

    static function ResetDomain($dn_vhost_fk)
    {
        global $zdbh;
        //Delete current records
        self::DeleteDomainRecords($dn_vhost_fk);
        //Create Default Records
        self::CreateDefaultRecords($dn_vhost_fk);
        self::$ok = true;
    }

    static function AddMissing()
    {
        global $zdbh;
        global $controller;
        $vhosts = array();
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $vhosts[] = $row['vh_id_pk'];
                }
            }
        }
        if (!fs_director::CheckForEmptyValue($vhosts)) {
            foreach ($vhosts as $vhost) {
                $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk = :vhost AND dn_deleted_ts IS NULL";
                $numrows = $zdbh->prepare($sql);
                $numrows->bindParam(':vhost', $vhost);
                if ($numrows->execute()) {
                    if ($numrows->fetchColumn() == 0) {
                        self::CreateDefaultRecords($vhost);
                        $numrecords++;
                    }
                }
            }
            self::$addmissing = $numrecords;
        }
    }

    static function DeleteType()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $type = $controller->GetControllerRequest('FORM', 'inType');
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_type_vc = :type AND dn_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':type', $type);


        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $type = $controller->GetControllerRequest('FORM', 'inType');
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_type_vc = :type AND dn_deleted_ts IS NULL");
                $sql->bindParam(':type', $type);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $type = $controller->GetControllerRequest('FORM', 'inType');
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk AND dn_type_vc = :type");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->bindParam(':type', $type);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deletedtype = $numrecords;
                self::$type = $controller->GetControllerRequest('FORM', 'inType');
            }
        }
    }

    static function Purge()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NOT NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NOT NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $delete_record = $zdbh->prepare("DELETE FROM x_dns WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();

                    $numrecords++;
                }
                self::$purged = $numrecords;
            }
        }
    }

    static function DeleteAll()
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deleted = $numrecords;
            }
        }
    }

    static function DeleteDomainRecords($domainid)
    {
        global $zdbh;
        global $controller;
        $numrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_vhost_fk=:domainid AND dn_deleted_ts IS NULL";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':domainid', $domainid);
        if ($numrows->execute()) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_vhost_fk=:domainid AND dn_deleted_ts IS NULL");
                $sql->bindParam(':domainid', $domainid);
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $time = time();
                    $delete_record = $zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=:time WHERE dn_id_pk = :dn_id_pk");
                    $delete_record->bindParam(':time', $time);
                    $delete_record->bindParam(':dn_id_pk', $row['dn_id_pk']);
                    $delete_record->execute();
                    $numrecords++;
                }
                self::$deleted = $numrecords;
            }
        }
    }

    static function SetPerms()
    {
        $bindlog = ctrl_options::GetSystemOption('bind_log');
        privilege::run('bind_log_chown', array($bindlog));
        privilege::run('bind_log_chmod', array($bindlog));
    }

    static function ClearErrors()
    {
        $bindlog = ctrl_options::GetSystemOption('bind_log');
        // Ensure group=www and mode=0664 before writing.
        self::SetPerms();
        if (is_writable($bindlog)) {
            $log = $bindlog;
            if (file_exists($bindlog)) {
                $handle = @fopen($log, "r");
                $getlog = array();
                if ($handle) {
                    while (!feof($handle)) {
                        $buffer = fgets($handle, 4096);
                        if (strstr($buffer, 'error:') || strstr($buffer, 'error ')) {
                            $line = "";
                        } else {
                            $line = $buffer;
                        }
                        $getlog[] = $line;
                    }fclose($handle);
                }

                $fp = fopen($log, 'w');
                foreach ($getlog as $key => $value) {
                    fwrite($fp, $value);
                }
                fclose($fp);
            }
        } else {
            self::$notwritable = true;
        }
    }

    static function ClearWarnings()
    {
        $bindlog = ctrl_options::GetSystemOption('bind_log');
        // Ensure group=www and mode=0664 before writing.
        self::SetPerms();
        if (is_writable($bindlog)) {
            $log = $bindlog;
            if (file_exists($bindlog)) {
                $handle = @fopen($log, "r");
                $getlog = array();
                if ($handle) {
                    while (!feof($handle)) {
                        $buffer = fgets($handle, 4096);
                        if (strstr($buffer, 'warning:') || strstr($buffer, 'warning ')) {
                            $line = "";
                        } else {
                            $line = $buffer;
                        }
                        $getlog[] = $line;
                    }fclose($handle);
                }

                $fp = fopen($log, 'w');
                foreach ($getlog as $key => $value) {
                    fwrite($fp, $value);
                }
                fclose($fp);
            }
        } else {
            self::$notwritable = true;
        }
    }

    static function ClearLog()
    {
        $bindlog = ctrl_options::GetSystemOption('bind_log');
        // Ensure group=www and mode=0664 before writing.
        self::SetPerms();
        if (is_writable($bindlog)) {
            $log = $bindlog;
            if (file_exists($bindlog)) {
                // PHP 8: fwrite($fp,...) con $fp=false lanza TypeError que 500ea; comprobar el recurso.
                $fp = fopen($log, 'w');
                if ($fp !== false) {
                    fwrite($fp, '');
                    fclose($fp);
                }
            }
        } else {
            self::$notwritable = true;
        }
    }

    static function DisplayDNSUsagepChart()
    {
        global $zdbh;
        global $controller;
        $numtotalrecords = 0;
        $numactiverecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $numtotalrecords++;
                }
            }
        }
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $numactiverecords++;
                }
            }
        }
        $total = $numtotalrecords;
        $active = $numactiverecords;
        $deleted = $total - $active;
        $line = "<h2>DNS Database Usage</h2>";
        $line .= "<img src=\"etc/lib/charts/svg_pie.php?score=" . $active . "::" . $deleted . "&labels=Active:_" . $active . "::Deleted:_" . $deleted . "&imagesize=320::200\"/>";
        return $line;
    }

    static function DisplayRecordsUsagepChart()
    {
        global $zdbh;
        global $controller;
        $numtotalrecords = 0;
        $numArecords = 0;
        $numAAAArecords = 0;
        $numMXrecords = 0;
        $numCNAMErecords = 0;
        $numTXTrecords = 0;
        $numSRVrecords = 0;
        $numSPFrecords = 0;
        $numNSrecords = 0;
        $sql = "SELECT COUNT(*) FROM x_dns";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    $numtotalrecords++;
                }
            }
        }
        $sql = "SELECT COUNT(*) FROM x_dns WHERE dn_deleted_ts IS NULL";
        if ($numrows = $zdbh->query($sql)) {
            if ($numrows->fetchColumn() <> 0) {
                $sql = $zdbh->prepare("SELECT * FROM x_dns WHERE dn_deleted_ts IS NULL");
                $sql->execute();
                while ($row = $sql->fetch()) {
                    if ($row['dn_type_vc'] == "A") {
                        $numArecords++;
                    }
                    if ($row['dn_type_vc'] == "AAAA") {
                        $numAAAArecords++;
                    }
                    if ($row['dn_type_vc'] == "MX") {
                        $numMXrecords++;
                    }
                    if ($row['dn_type_vc'] == "CNAME") {
                        $numCNAMErecords++;
                    }
                    if ($row['dn_type_vc'] == "TXT") {
                        $numTXTrecords++;
                    }
                    if ($row['dn_type_vc'] == "SRV") {
                        $numSRVrecords++;
                    }
                    if ($row['dn_type_vc'] == "SPF") {
                        $numSPFrecords++;
                    }
                    if ($row['dn_type_vc'] == "NS") {
                        $numNSrecords++;
                    }
                }
            }
        }
        $total = $numtotalrecords;
        $Arecords = $numArecords;
        $AAAArecords = $numAAAArecords;
        $MXrecords = $numMXrecords;
        $CNAMErecords = $numCNAMErecords;
        $TXTrecords = $numTXTrecords;
        $SRVrecords = $numSRVrecords;
        $SPFrecords = $numSPFrecords;
        $NSrecords = $numNSrecords;
        $line = "<h2>Record Types Usage</h2>";
        $line .= "<img src=\"etc/lib/charts/svg_pie.php?score=" . $Arecords . "::" . $NSrecords . "::" . $MXrecords . "::" . $SPFrecords . "::" . $TXTrecords . "::" . $SRVrecords . "::" . $CNAMErecords . "::" . $AAAArecords . "&labels=A:_" . $Arecords . "::NS:_" . $NSrecords . "::MX:_" . $MXrecords . "::SPF:_" . $SPFrecords . "::TXT:_" . $TXTrecords . "::SRV:_" . $SRVrecords . "::CNAME:_" . $CNAMErecords . "::AAAA:_" . $AAAArecords . "&imagesize=320::200\"/>";
        return $line;
    }

    static function CreateDefaultRecords($vh_acc_fk)
    {
        global $zdbh;
        global $controller;
        // CRIT-4 FIX: use prepared statement — $vh_acc_fk was concatenated directly into query()
        $domainID  = (int)$vh_acc_fk;
        $stmtVhost = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_id_pk = :did AND vh_deleted_ts IS NULL");
        $stmtVhost->bindParam(':did', $domainID, PDO::PARAM_INT);
        $stmtVhost->execute();
        $domainName = $stmtVhost->fetch();
        $userID = $domainName['vh_acc_fk'];
        if (!fs_director::CheckForEmptyValue(ctrl_options::GetSystemOption('server_ip'))) {
            $target = ctrl_options::GetSystemOption('server_ip');
        } else {
            $target = $_SERVER["SERVER_ADDR"];
        }
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            '@',
                                                            3600,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'CNAME',
                                                            'www',
                                                            3600,
                                                            '@',
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'CNAME',
                                                            'ftp',
                                                            3600,
                                                            '@',
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'mail',
                                                            86400,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'MX',
                                                            '@',
                                                            86400,
                                                            :vh_name_vc,
                                                            10,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $Domain = 'mail.' . $domainName['vh_name_vc'];
        $sql->bindParam(':vh_name_vc', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'ns1',
                                                            172800,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'A',
                                                            'ns2',
                                                            172800,
                                                            :target,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->bindParam(':domainID', $domainID);
        $sql->bindParam(':target', $target);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'NS',
                                                            '@',
                                                            172800,
                                                            :vh_name_vc2,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        // NS compartido del panel (fallback vanity ns1.<dominio> si no está configurado)
        $ns1Cfg = ctrl_options::GetSystemOption('dns_ns1');
        $Domain = !fs_director::CheckForEmptyValue($ns1Cfg) ? $ns1Cfg : ('ns1.' . $domainName['vh_name_vc']);
        $sql->bindParam(':vh_name_vc', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':vh_name_vc2', $domainName['vh_name_vc']);
        $sql->execute();
        $sql = $zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,
                                                            dn_name_vc,
                                                            dn_vhost_fk,
                                                            dn_type_vc,
                                                            dn_host_vc,
                                                            dn_ttl_in,
                                                            dn_target_vc,
                                                            dn_priority_in,
                                                            dn_weight_in,
                                                            dn_port_in,
                                                            dn_created_ts) VALUES (
                                                            :userID,
                                                            :vh_name_vc,
                                                            :domainID,
                                                            'NS',
                                                            '@',
                                                            172800,
                                                            :ns2,
                                                            NULL,
                                                            NULL,
                                                            NULL,
                                                            :time)");
        $sql->bindParam(':userID', $userID);
        $ns2Cfg = ctrl_options::GetSystemOption('dns_ns2');
        $Domain = !fs_director::CheckForEmptyValue($ns2Cfg) ? $ns2Cfg : ('ns2.' . $domainName['vh_name_vc']);
        $sql->bindParam(':ns2', $Domain);
        $sql->bindParam(':domainID', $domainID);
        $time = time();
        $sql->bindParam(':time', $time);
        $sql->bindParam(':vh_name_vc', $domainName['vh_name_vc']);
        $sql->execute();
        return;
    }

    static function ViewErrors()
    {
        $bindlog = ctrl_options::GetSystemOption('bind_log');
        $logerror = array();
        $logwarning = array();
        $getlog = array();
        if (file_exists($bindlog)) {
            $handle = @fopen($bindlog, "r");
            $getlog = array();
            if ($handle) {
                while (!feof($handle)) {
                    $buffer = fgets($handle, 4096);
                    $getlog[] = $buffer;
                    if (strstr($buffer, 'error:') || strstr($buffer, 'error ')) {
                        $logerror[] = $buffer;
                    }
                    if (strstr($buffer, 'warning:') || strstr($buffer, 'warning ')) {
                        $logwarning[] = $buffer;
                    }
                }fclose($handle);
                if (!fs_director::CheckForEmptyValue($logerror)) {
                    self::$logerror = $logerror;
                }
                if (!fs_director::CheckForEmptyValue($logwarning)) {
                    self::$logwarning = $logwarning;
                }
                if (!fs_director::CheckForEmptyValue($getlog)) {
                    self::$getlog = $getlog;
                }
            }
        }
    }

    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$ok)) {
            return ui_sysmessage::shout(ui_language::translate("Changes to your settings have been saved successfully!"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$notwritable)) {
            return ui_sysmessage::shout(ui_language::translate("No permission to write to log file."), "zannounceerror");
        }
        if (!fs_director::CheckForEmptyValue(self::$forceupdate)) {
            return ui_sysmessage::shout(ui_language::translate("All zone records will be updated on next daemon run."), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$reset)) {
            return ui_sysmessage::shout(number_format(self::$reset) . " " . ui_language::translate("Domains records where reset to default"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$addmissing)) {
            return ui_sysmessage::shout(number_format(self::$addmissing) . " " . ui_language::translate("Domains records were created"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$deletedtype)) {
            return ui_sysmessage::shout(number_format(self::$deletedtype) . " '" . self::$type . "' " . ui_language::translate("Records where marked as deleted from the database"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$deleted)) {
            return ui_sysmessage::shout(number_format(self::$deleted) . " " . ui_language::translate("Records where marked as deleted from the database"), "zannounceok");
        }
        if (!fs_director::CheckForEmptyValue(self::$purged)) {
            return ui_sysmessage::shout(number_format(self::$purged) . " " . ui_language::translate("Records where purged from the database"), "zannounceok");
        }
        return;
    }

    static function TriggerDNSUpdate($id)
    {
        global $zdbh;
        global $controller;
        $GetRecords = ctrl_options::GetSystemOption('dns_hasupdates');
        $records = explode(",", $GetRecords);
        foreach ($records as $record) {
            $RecordArray[] = $record;
        }
        if (!in_array($id, $RecordArray)) {
            $newlist = $GetRecords . "," . $id;
            $newlist = str_replace(",,", ",", $newlist);
            $sql = "UPDATE x_settings SET so_value_tx=:newlist WHERE so_name_vc='dns_hasupdates'";
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':newlist', $newlist);
            $sql->execute();
            return true;
        }
    }

    static function CheckLogReadable($filename)
    {
        if (is_readable($filename)) {
            $retval = "<font color=\"green\">" . ui_language::translate("Connected to log file") . "</font>";
        } else {
            $retval = "<font color=\"red\">" . ui_language::translate("Log file is not Readable") . "</font>";
        }
        return $retval;
    }

    static function CheckLogWritable($filename)
    {
        if (is_readable($filename)) {
            if (is_writable($filename)) {
                $retval = "<font color=\"green\">" . ui_language::translate("(writable)") . "</font>";
            } else {
                $retval = "<font color=\"red\">" . ui_language::translate("(readonly)") . "</font>";
            }
        } else {
            $retval = NULL;
        }
        return $retval;
    }

}
