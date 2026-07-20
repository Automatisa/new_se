<?php
if (!class_exists('privilege')) {
        require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    }
if (!class_exists('fpm_pool_manager')) {
        require_once '/usr/local/bulwark/dryden/sys/fpm_pool_manager.class.php';
    }
echo fs_filehandler::NewLine() . "START Apache Config Hook." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Apache Config')) {
    echo "Apache Admin module ENABLED..." . fs_filehandler::NewLine();
    TriggerApacheQuotaUsage();
    // Regenerar pools PHP-FPM en cada ciclo del daemon
    $fpmCount = fpm_pool_manager::Regenerate();
    echo "PHP-FPM pools: " . $fpmCount . " activos." . fs_filehandler::NewLine();
    // Aplicar límites de recursos por usuario (RACCT/RCTL) desde el paquete: contiene DoS
    // (fork-bombs, RAM/CPU) de un inquilino. No-op si RACCT no está activo en el kernel.
    if (!class_exists('rctl_manager')) {
        require_once '/usr/local/bulwark/dryden/sys/rctl_manager.class.php';
    }
    $rctlCount = rctl_manager::ApplyAll();
    echo "rctl: limites aplicados a " . $rctlCount . " usuarios." . fs_filehandler::NewLine();
    // Aplicar cuotas de disco UFS por usuario desde el paquete: sobre cuota = no puede
    // escribir (EDQUOT) pero la web sigue sirviendo. No-op si las cuotas UFS no están activas.
    if (!class_exists('disk_quota_manager')) {
        require_once '/usr/local/bulwark/dryden/sys/disk_quota_manager.class.php';
    }
    $dqCount = disk_quota_manager::ApplyAll();
    echo "disk-quota: aplicadas a " . $dqCount . " usuarios." . fs_filehandler::NewLine();
    if (ctrl_options::GetSystemOption('apache_changed') == strtolower("true")) {
        echo "Apache Config has changed..." . fs_filehandler::NewLine();
		
        echo "Begin writing Apache Config to: " . ctrl_options::GetSystemOption('apache_vhost') . fs_filehandler::NewLine();
        WriteVhostConfigFile();
		
		# If Apache vhost file passes configuration test, run Apache vhost file Backup. Helping To prevent backing up a currupt vhost.conf
		if ( ctrl_options::GetSystemOption('apache_changed') != strtolower("true") ) {
			
			if (ctrl_options::GetSystemOption('apache_backup') == strtolower("true")) {
				echo "Backing up Apache Config to: " . ctrl_options::GetSystemOption('apache_budir') . fs_filehandler::NewLine();
				BackupVhostConfigFile();
			}
		}
    } else {
        echo "Apache Config has NOT changed...nothing to do." . fs_filehandler::NewLine();
    }
} else {
    echo "Apache Admin module DISABLED...nothing to do." . fs_filehandler::NewLine();
}
echo "END Apache Config Hook." . fs_filehandler::NewLine();

/**
 *
 * @param string $vhostName
 * @param numeric $customPort
 * @param string $userEmail[5~ * @return string
 *
 */
function BuildVhostPortForward($vhostName, $customPort, $userEmail) {
		
	$customPort_in = $customPort;
	
    $line = "# DOMAIN: " . $vhostName . fs_filehandler::NewLine();
    $line .= "# PORT FORWARD FROM ".ctrl_options::GetSystemOption('apache_port')." TO: " . $customPort_in . fs_filehandler::NewLine();
    $line .= "<Virtualhost *:".$apache_port.">" . fs_filehandler::NewLine();
    $line .= "ServerName " . $vhostName . fs_filehandler::NewLine();
	if ($vhostName != ctrl_options::GetSystemOption('bulwark_domain') ) 
		$line .= "ServerAlias www." . $vhostName . fs_filehandler::NewLine();
    $line .= "ServerAdmin " . $userEmail . fs_filehandler::NewLine();
    $line .= "RewriteEngine on" . fs_filehandler::NewLine();
    $line .= "ReWriteCond %{SERVER_PORT} !^" . $customPort_in . "$" . fs_filehandler::NewLine();
    # Excluir el challenge ACME (Let's Encrypt): se sirve por HTTP en el puerto 80 y NO debe
    # redirigirse, o se rompe la emision/renovacion de certificados (LE sigue redirects en :80).
    $line .= "RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/" . fs_filehandler::NewLine();
    $line .= ( $customPort_in === "443" ) ? "RewriteRule ^/(.*) https://%{HTTP_HOST}/$1 [NC,R,L] " . fs_filehandler::NewLine() : "RewriteRule ^/(.*) http://%{HTTP_HOST}:" . $customPort . "/$1 [NC,R,L] " . fs_filehandler::NewLine();
    $line .= "</virtualhost>" . fs_filehandler::NewLine();
	$line .= "##-------" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();
		
    return $line;
}

# vhost SSL ReWrite http to https -tg
function BuildVhostReWriteSSL($vhostName, $userEmail) {
		
    $line = "# DOMAIN: " . $vhostName . fs_filehandler::NewLine();
    $line .= "# SSL REDIRECT" . fs_filehandler::NewLine();
    $line .= "<Virtualhost *:".ctrl_options::GetSystemOption('apache_port').">" . fs_filehandler::NewLine();
    $line .= "ServerName " . $vhostName . fs_filehandler::NewLine();
	if ($vhostName != ctrl_options::GetSystemOption('bulwark_domain') ) 
		$line .= "ServerAlias www." . $vhostName . fs_filehandler::NewLine();
    $line .= "ServerAdmin " . $userEmail . fs_filehandler::NewLine();
    $line .= "RewriteEngine On" . fs_filehandler::NewLine();
	$line .= "RewriteCond %{HTTPS} !=on" . fs_filehandler::NewLine();
	# Excluir el challenge ACME (Let's Encrypt), que se sirve por HTTP, para no
	# romper la emision/renovacion de certificados al forzar HTTPS.
	$line .= "RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/" . fs_filehandler::NewLine();
	$line .= "RewriteRule ^/?(.*) https://%{SERVER_NAME}/$1 [R,L]" . fs_filehandler::NewLine();
    $line .= "</virtualhost>" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();
	$line .= "##-------" . fs_filehandler::NewLine();
	$line .= fs_filehandler::NewLine();

    return $line;
}

// Vhost HTTP (:80) que SIRVE el sitio real (mismo docroot/FPM que el bloque activo). Se usa
// cuando el dominio tiene SSL pero el cliente NO fuerza HTTPS (vh_forcessl_in=0): entonces el
// sitio se sirve por HTTP y por HTTPS. Cuando fuerza (default), el :80 es un redirect (arriba).
// Multi-IP doble pila: construye la lista de direcciones del <VirtualHost> para un puerto.
// v4 (o '*') siempre; si el vhost tiene IPv6 dedicada, añade [ipv6]:puerto. Ej:
//   "192.168.1.243:80 [fd00::150]:80"   o   "*:80".
function bulwark_vhost_addrs($v4spec, $ip6, $port) {
    $s = $v4spec . ':' . $port;
    if ($ip6 !== null && $ip6 !== '') { $s .= ' [' . $ip6 . ']:' . $port; }
    return $s;
}

function BuildRegularHttpVhost($rowvhost, $vhostIp, $vhostPort, $serveralias, $useremail, $RootDir, $_vhpaths, $_bwlogdir) {
    $line  = "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    $line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $vhostPort) . ">" . fs_filehandler::NewLine();
    $line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    if (!empty($serveralias))
        $line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
    $line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
    $line .= 'DocumentRoot ' . '"' . $RootDir . '"' . fs_filehandler::NewLine();
    if (!is_dir($_vhpaths['logs'])) {
        fs_director::CreateDirectory($_vhpaths['logs']);
    }
    $line .= 'ErrorLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-error.log" ' . fs_filehandler::NewLine();
    $line .= 'CustomLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-access.log" ' . ctrl_options::GetSystemOption('access_log_format') . fs_filehandler::NewLine();
    $line .= 'CustomLog "' . $_bwlogdir . '/' . $rowvhost['vh_name_vc'] . '-bandwidth.log" ' . ctrl_options::GetSystemOption('bandwidth_log_format') . fs_filehandler::NewLine();
    $line .= '<Directory ' . $RootDir . '>' . fs_filehandler::NewLine();
    $line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
    $line .= "    AllowOverride FileInfo AuthConfig Limit" . fs_filehandler::NewLine();
    $line .= "    Require all granted" . fs_filehandler::NewLine();
    $line .= "</Directory>" . fs_filehandler::NewLine();
    $line .= BuildFPMHandler($rowvhost['vh_directory_vc']) . fs_filehandler::NewLine();
    $line .= "AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript" . fs_filehandler::NewLine();
    $line .= AppendErrorPageDirectives($_vhpaths['errorpages'], '/_errorpages/');
    $line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
    $line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
    $line .= ctrl_options::GetSystemOption('global_vhcustom') . fs_filehandler::NewLine();
    $line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
    $line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
    $line .= "</virtualhost>" . fs_filehandler::NewLine();
    $line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    $line .= fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    return $line;
}

/**
 * Sanitiza el campo vh_custom_tx antes de incluirlo en el fichero de vhosts.
 *
 * vh_custom_tx es editable por el admin/reseller y se inserta dentro de un
 * bloque <VirtualHost>. Sin este filtro, un valor como:
 *   </VirtualHost>\nAlias / /etc/passwd
 * permitiría inyectar directivas Apache globales (fuera del bloque del dominio).
 *
 * Política: se eliminan líneas que contengan </VirtualHost (case-insensitive)
 * o que abran un nuevo <VirtualHost. El resto de directivas se pasa tal cual.
 */
function sanitizeVhCustom(?string $raw): string
{
    if ($raw === null || trim($raw) === '') return '';
    $lines  = explode("\n", str_replace("\r\n", "\n", $raw));
    $clean  = [];
    foreach ($lines as $line) {
        $t = strtolower(trim($line));
        if (strpos($t, '</virtualhost') !== false) continue;
        if (preg_match('/^<virtualhost\b/i', $t))  continue;
        $clean[] = $line;
    }
    return implode("\n", $clean);
}

function WriteVhostConfigFile() {
    global $zdbh;
		
	if ((double) sys_versions::ShowApacheVersion() < 2.4) {
        $apgrant = "0";
    } else {
        $apgrant = "1";
    }
	
    # Get email for server admin of Bulwark
    $getserveremail = $zdbh->query("SELECT ac_email_vc FROM x_accounts where ac_id_pk=1")->fetch();
    $serveremail = ( $getserveremail['ac_email_vc'] != "" ) ? $getserveremail['ac_email_vc'] : "postmaster@" . ctrl_options::GetSystemOption('bulwark_domain');

    $VHostDefaultPort = ctrl_options::GetSystemOption('apache_port');
    $customPorts = array(ctrl_options::GetSystemOption('bulwark_port'));
	
    $portQuery = $zdbh->prepare("SELECT vh_custom_port_in FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $portQuery->execute();
		while ($rowport = $portQuery->fetch()) {
			$customPorts[] = (empty($rowport['vh_custom_port_in'])) ? $VHostDefaultPort : $rowport['vh_custom_port_in'];
			
			# Add vh_ssl_port_in ports to list array 
			$portQuery2 = $zdbh->prepare("SELECT vh_ssl_port_in FROM x_vhosts WHERE vh_deleted_ts IS NULL");
			$portQuery2->execute();
			while ($rowport2 = $portQuery2->fetch()) {
				$customPorts[] = (empty($rowport2['vh_ssl_port_in'])) ? $VHostDefaultPort : $rowport2['vh_ssl_port_in'];
			}	
		}
	
    $customPortList = array_unique($customPorts);
					
    /*
     * ###########################################################################?###################################
     * #
     * # Default Virtual Host Container
     * #
     * ###########################################################################?###################################
     */

    $line = "################################################################" . fs_filehandler::NewLine();
    $line .= "# Apache VHOST configuration file" . fs_filehandler::NewLine();
    $line .= "# Automatically generated by Bulwark " . sys_versions::ShowBulwarkVersion() . fs_filehandler::NewLine();
    $line .= "# Generated on: " . date(ctrl_options::GetSystemOption('bulwark_df'), time()) . fs_filehandler::NewLine();
    $line .= "#==== YOU MUST NOT EDIT THIS FILE : IT WILL BE OVERWRITTEN ====" . fs_filehandler::NewLine();
    $line .= "# Use Bulwark Menu -> Admin -> Module Admin -> Apache config" . fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= fs_filehandler::NewLine();

    # Listen is mandatory for each port <> 80 (80 is defined in system httpd.conf)
	# For each custom port, skip 80 to avoid "multiple Listeners on same port" error
    foreach ($customPortList as $port) {
        if ($port != '80' && $port != 80) {
            $line .= "Listen " . $port . fs_filehandler::NewLine();
        }
    }
	
	$line .= fs_filehandler::NewLine();
	$line .= "# Configuration for Bulwark control panel." . fs_filehandler::NewLine();
	if (ctrl_options::GetSystemOption('panel_ssl_tx') == null) {
		
		##
		## Bulwark Control Panel default vhost entry
		##
		
		$line .= "<VirtualHost *:" . ctrl_options::GetSystemOption('bulwark_port') . ">" . fs_filehandler::NewLine();
		$line .= "ServerAdmin " . $serveremail . fs_filehandler::NewLine();
		$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('bulwark_root') . '"' . fs_filehandler::NewLine();
		$line .= "ServerName " . ctrl_options::GetSystemOption('bulwark_domain') . fs_filehandler::NewLine();
		
		# Vhost PHP settings
		$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
		$line .= "#php_admin_value open_basedir " . '"' . "/etc/bulwark/" . ctrl_options::GetSystemOption('openbase_seperator') 
				. "/var/bulwark/" . ctrl_options::GetSystemOption('openbase_seperator')
				. "/var/spool/" . '"' . fs_filehandler::NewLine(); 
				
		$line .= "SetEnv PHP_VALUE \"session.save_path=/var/bulwark/sessions\"" . fs_filehandler::NewLine();
		
		$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-error.log" ' . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-access.log" ' . ctrl_options::GetSystemOption('access_log_format') . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-bandwidth.log" ' . ctrl_options::GetSystemOption('bandwidth_log_format') . fs_filehandler::NewLine();
		
		$line .= AppendErrorPageDirectives(
			ctrl_options::GetSystemOption('bulwark_root') . '/etc/static/errorpages',
			'/etc/static/errorpages/'
		);
		$line .= '<Directory "' . ctrl_options::GetSystemOption('bulwark_root') . '">' . fs_filehandler::NewLine();
		$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
		$line .= "    AllowOverride All" . fs_filehandler::NewLine();
	
		if ((double) sys_versions::ShowApacheVersion() < 2.4) {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		} else {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		}
	
		$line .= "</Directory>" . fs_filehandler::NewLine();
		$line .= "# Custom settings (if any exist)" . fs_filehandler::NewLine();
	
		// Global custom Bulwark entry
		$line .= ctrl_options::GetSystemOption('global_zpcustom') . fs_filehandler::NewLine();
	
		$line .= "</VirtualHost>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		
	# Forwrd Bulwark Panel if SSL is in use
	# If vhost SSL_TX not null create spearate <virtualhost>
	# Build Vhost SSL section
    } elseif (ctrl_options::GetSystemOption('panel_ssl_tx') != null) {

		$panelSslTx   = ctrl_options::GetSystemOption('panel_ssl_tx');
		$panelSslPort = ctrl_options::GetSystemOption('bulwark_port');
		$panelCert    = '';
		$panelKey     = '';
		if (preg_match('/^SSLCertificateFile\s+(\S+)/m',    $panelSslTx, $m)) $panelCert = $m[1];
		if (preg_match('/^SSLCertificateKeyFile\s+(\S+)/m', $panelSslTx, $m)) $panelKey  = $m[1];

		# Panel SSL atado a la IP PRIMARIA (server_ip): el fallback por IP (sin SNI) solo sirve el
		# panel en esa IP; en cualquier OTRA IP por HTTPS se deniega (403), para que una IP de
		# cliente nunca muestre el login del panel. Si server_ip no es válida, se mantiene _default_.
		$srvIp       = (string)ctrl_options::GetSystemOption('server_ip');
		$panelIpBind = (filter_var($srvIp, FILTER_VALIDATE_IP) !== false) ? $srvIp : '_default_';
		# Doble pila: si hay IPv6 primaria (server_ip6), el panel escucha también en [v6]:port.
		# Sin server_ip6 configurada, $panelAddrs es idéntico al binding v4 de siempre.
		$srvIp6     = (string)ctrl_options::GetSystemOption('server_ip6');
		$panelAddrs = $panelIpBind . ":" . $panelSslPort;
		if ($panelIpBind !== '_default_'
			&& filter_var($srvIp6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$panelAddrs .= " [" . $srvIp6 . "]:" . $panelSslPort;
		}
		# Cluster DNS con túnel (WireGuard): si este nodo tiene IP de SINCRONIZACIÓN propia (la del
		# túnel), el panel —y con él la API /bin/api.php del cluster— también escucha ahí, para que
		# la sync entre nodos viaje por el túnel (si no, cae al _default_ y da 403). Sin túnel
		# (nd_sync_ip_vc NULL) esto no añade nada y el binding es el de siempre.
		$panelSyncIp = '';
		if ($pss = $zdbh->query("SELECT nd_sync_ip_vc FROM x_dns_nodes WHERE nd_is_self_in=1 AND nd_sync_ip_vc IS NOT NULL AND nd_sync_ip_vc <> '' LIMIT 1")) {
			$panelSyncIp = (string)$pss->fetchColumn();
		}
		if ($panelSyncIp !== '' && filter_var($panelSyncIp, FILTER_VALIDATE_IP) !== false && $panelSyncIp !== $panelIpBind) {
			$panelAddrs .= " " . $panelSyncIp . ":" . $panelSslPort;
		}
		if ($panelCert && $panelKey) {
			$line .= "# FALLBACK SSL (IP primaria): acceso por IP usa el cert del panel (aviso de navegador esperado)" . fs_filehandler::NewLine();
			$line .= "<VirtualHost " . $panelAddrs . ">" . fs_filehandler::NewLine();
			$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('bulwark_root') . '"' . fs_filehandler::NewLine();
			$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
			$line .= "SetEnv PHP_VALUE \"session.save_path=/var/bulwark/sessions\"" . fs_filehandler::NewLine();
			$line .= '<Directory "' . ctrl_options::GetSystemOption('bulwark_root') . '">' . fs_filehandler::NewLine();
			$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
			$line .= "    AllowOverride All" . fs_filehandler::NewLine();
			$line .= "    Require all granted" . fs_filehandler::NewLine();
			$line .= "</Directory>" . fs_filehandler::NewLine();
			$line .= "SSLEngine On" . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . $panelCert . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $panelKey . fs_filehandler::NewLine();
			$line .= "</VirtualHost>" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();

			# Denegar el panel en cualquier otra IP por HTTPS (solo si está atado a una IP concreta).
			if ($panelIpBind !== '_default_') {
				$line .= "# Otras IPs por HTTPS (sin vhost propio) -> 403, nunca el panel" . fs_filehandler::NewLine();
				$line .= "<VirtualHost _default_:" . $panelSslPort . ">" . fs_filehandler::NewLine();
				$line .= "SSLEngine On" . fs_filehandler::NewLine();
				$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
				$line .= "SSLCertificateFile " . $panelCert . fs_filehandler::NewLine();
				$line .= "SSLCertificateKeyFile " . $panelKey . fs_filehandler::NewLine();
				$line .= '<Location "/">' . fs_filehandler::NewLine();
				$line .= "    Require all denied" . fs_filehandler::NewLine();
				$line .= "</Location>" . fs_filehandler::NewLine();
				$line .= "</VirtualHost>" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
			}
		}

		# Panel en :80 segun la opcion panel_force_https:
		#  - true : redirigir HTTP -> HTTPS (fuerza HTTPS; excluye el challenge ACME)
		#  - false: servir el panel tambien por HTTP (ambos puertos accesibles)
		if (ctrl_options::GetSystemOption('panel_force_https') == strtolower('true')) {
			$line .= BuildVhostReWriteSSL(ctrl_options::GetSystemOption('bulwark_domain'), $serveremail);
		} else {
			$line .= "# DOMAIN: " . ctrl_options::GetSystemOption('bulwark_domain') . fs_filehandler::NewLine();
			$line .= "# PANEL HTTP (sin forzar HTTPS)" . fs_filehandler::NewLine();
			$line .= "<VirtualHost *:" . ctrl_options::GetSystemOption('apache_port') . ">" . fs_filehandler::NewLine();
			$line .= "ServerAdmin " . $serveremail . fs_filehandler::NewLine();
			$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('bulwark_root') . '"' . fs_filehandler::NewLine();
			$line .= "ServerName " . ctrl_options::GetSystemOption('bulwark_domain') . fs_filehandler::NewLine();
			$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
			$line .= "SetEnv PHP_VALUE \"session.save_path=/var/bulwark/sessions\"" . fs_filehandler::NewLine();
			$line .= '<Directory "' . ctrl_options::GetSystemOption('bulwark_root') . '">' . fs_filehandler::NewLine();
			$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
			$line .= "    AllowOverride All" . fs_filehandler::NewLine();
			$line .= "    Require all granted" . fs_filehandler::NewLine();
			$line .= "</Directory>" . fs_filehandler::NewLine();
			$line .= "</VirtualHost>" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine() . "##-------" . fs_filehandler::NewLine() . fs_filehandler::NewLine();
		}
		$line .= "# PANEL HAS SSL ENABLED" . fs_filehandler::NewLine();
		$line .= "<VirtualHost " . $panelAddrs . ">" . fs_filehandler::NewLine();
		$line .= "ServerAdmin " . $serveremail . fs_filehandler::NewLine();
		$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('bulwark_root') . '"' . fs_filehandler::NewLine();
		$line .= "ServerName " . ctrl_options::GetSystemOption('bulwark_domain') . fs_filehandler::NewLine();
		
		# Vhost PHP settings
		$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
		$line .= "#php_admin_value open_basedir " . '"' . "/etc/bulwark/" . ctrl_options::GetSystemOption('openbase_seperator') 
				. "/var/bulwark/" . ctrl_options::GetSystemOption('openbase_seperator')
				. "/var/spool/" . '"' . fs_filehandler::NewLine(); 
				
		# Set Function Blacklist 
		// php_admin_value sp.configuration_file not supported with PHP-FPM
		
		$line .= "SetEnv PHP_VALUE \"session.save_path=/var/bulwark/sessions\"" . fs_filehandler::NewLine();
	
		$line .= 'ErrorLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-error.log" ' . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-access.log" ' . ctrl_options::GetSystemOption('access_log_format') . fs_filehandler::NewLine();
		$line .= 'CustomLog "' . ctrl_options::GetSystemOption('log_dir') . 'bulwark-bandwidth.log" ' . ctrl_options::GetSystemOption('bandwidth_log_format') . fs_filehandler::NewLine();
	
		$line .= AppendErrorPageDirectives(
			ctrl_options::GetSystemOption('bulwark_root') . '/etc/static/errorpages',
			'/etc/static/errorpages/'
		);
		$line .= '<Directory "' . ctrl_options::GetSystemOption('bulwark_root') . '">' . fs_filehandler::NewLine();
		$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
		$line .= "    AllowOverride All" . fs_filehandler::NewLine();
	
		if ((double) sys_versions::ShowApacheVersion() < 2.4) {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		} else {
			$line .= "    Require all granted" . fs_filehandler::NewLine();
		}
		
		$line .= "</Directory>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();

		# SSL Settings
		$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
		$line .= ctrl_options::GetSystemOption('panel_ssl_tx') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();

		$line .= "# Custom settings are loaded below this line (if any exist)" . fs_filehandler::NewLine();
		// Global custom Bulwark entry
		$line .= ctrl_options::GetSystemOption('global_zpcustom') . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		
		$line .= "</VirtualHost>" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
				
	}
		
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= "# Bulwark generated VHOST configurations below....." . fs_filehandler::NewLine();
    $line .= "################################################################" . fs_filehandler::NewLine();
    $line .= fs_filehandler::NewLine();

    /*
     * ##############################################################################################################
     * #
     * # All Virtual Host Containers
     * #
     * ##############################################################################################################
     */
	
	#
    # Bulwark virtual host container configuration
	#
	$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $sql->execute();
    while ($rowvhost = $sql->fetch()) {
	
	# Grab some variables we will use for later...
	$vhostuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
	$bandwidth = ctrl_users::GetQuotaUsages('bandwidth', $vhostuser['userid']);
	$diskspace = ctrl_users::GetQuotaUsages('diskspace', $vhostuser['userid']);
	# Set the vhosts to "LIVE"
	$vsql = $zdbh->prepare("UPDATE x_vhosts SET vh_active_in=1 WHERE vh_id_pk=:id");
	$vsql->bindParam(':id', $rowvhost['vh_id_pk']);
	$vsql->execute();
	
	# Add a default email if no email found for client.
	$useremail = ( fs_director::CheckForEmptyValue($vhostuser['email']) ) ? "postmaster@" . $rowvhost['vh_name_vc'] : $vhostuser['email'];
	
	# Check if domain or subdomain to see if we add an alias with 'www'
	$serveralias = ( $rowvhost['vh_type_in'] == 2 ) ? '' : " www." . $rowvhost['vh_name_vc'];
	
	# Check if site is ssl enabled to pevent duplicate Port 443
	$vhostPort = ( fs_director::CheckForEmptyValue($rowvhost['vh_custom_port_in']) ) ? $VHostDefaultPort : $rowvhost['vh_custom_port_in'];

	$vhostIp = ( fs_director::CheckForEmptyValue($rowvhost['vh_custom_ip_vc']) ) ? "*" : $rowvhost['vh_custom_ip_vc'];
	
	# Get Package php and cgi enabled options
	#*************************************************
	# Nueva estructura: hosted_dir/username/vh_directory_vc/public_html/
	$_vhpaths  = ctrl_options::GetVhostPaths($vhostuser['username'], $rowvhost['vh_directory_vc']);
	$RootDir   = $_vhpaths['public_html'];
	$_bwlogdir = '/var/bulwark/logs/bandwidth/' . $vhostuser['username'] . '/' . $rowvhost['vh_directory_vc'];
	if (!is_dir($_bwlogdir)) {
		mkdir($_bwlogdir, 0750, true);
		chgrp($_bwlogdir, 'www');
	}
	##
	### Stop Snuff Protection managemenet HERE. ------- DO NOT EDIT THIS CODE ABOVE!!!!!
	##

	# Domain is enabled
	# Effective state considers reseller cascade: disabled reseller → client disabled, etc.
	$effectiveState = ctrl_users::GetEffectiveAccountState($rowvhost['vh_acc_fk']);
	# Pre-compute static dir: parking = domain manually suspended but account active
	if ($effectiveState === 'suspended')        $staticDir = 'suspended';
	elseif ($effectiveState === 'active')        $staticDir = 'parking';
	else                                         $staticDir = 'disabled';
	if ($rowvhost['vh_enabled_in'] == 1 && (
		$effectiveState === 'active' ||
		ctrl_options::GetSystemOption('apache_allow_disabled') == strtolower("true"))) {
		/*
		 * ##################################################
		 * #
		 * # Disk Quotas Check
		 * #
		 * ##################################################
		 */
		# Domain is beyond its diskusage
		#
		# LÓGICA CORREGIDA: exceder la cuota de DISCO ya NO tumba la web. Un sitio sobre
		# cuota debe seguir SIRVIENDO su contenido (lecturas); lo único que debe impedirse
		# es ESCRIBIR más, y eso se hace a nivel de sistema de ficheros con cuota UFS por
		# uid (h_user) — el kernel devuelve EDQUOT en los write() pero el servicio sigue.
		# Por eso esta condición se anula (false): el vhost se genera normal aunque esté
		# sobre cuota. (El ancho de banda mantiene su política aparte, más abajo.)
		if (false && $vhostuser['diskquota'] != 0 && $diskspace > $vhostuser['diskquota']) {
			if ($rowvhost['vh_ssl_tx'] == null) {
				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR DISK QUOTA OVERAGE" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $vhostPort) . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= fs_filehandler::NewLine();
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null ) {
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR DISK QUOTA OVERAGE & HAS SSL ENABLED" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $rowvhost['vh_ssl_port_in']) . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'diskexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();	
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();		
		/*
		 * ##################################################
		 * #
		 * # Bandwidth Quotas Check
		 * #
		 * ##################################################
		 */
		# Domain is beyond its quota
		} elseif ($vhostuser['bandwidthquota'] != 0 && $bandwidth > $vhostuser['bandwidthquota']) {
			if ($rowvhost['vh_ssl_tx'] == null) {
				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR BANDWIDTH OVERAGE" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $vhostPort) . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				$line .= fs_filehandler::NewLine();
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null ) {
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN DISABLED FOR BANDWIDTH OVERAGE & HAS SSL ENABLED" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $rowvhost['vh_ssl_port_in']) . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('static_dir') . 'bandwidthexceeded">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
							
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();	
		/*
		 * ##################################################
		 * #
		 * # Parked Domain
		 * #
		 * ##################################################
		 */
		# Domain is a PARKED domain.
		} elseif ($rowvhost['vh_type_in'] == 3) {
			if ($rowvhost['vh_ssl_tx'] == null) {			
				# Load template file into vhost config to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS BEEN PARKED" . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $vhostPort) . ">" . fs_filehandler::NewLine();
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				$line .= 'DocumentRoot "' . ctrl_options::GetSystemOption('parking_path') . '"' . fs_filehandler::NewLine();
				$line .= '<Directory "' . ctrl_options::GetSystemOption('parking_path') . '">' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				$line .= "    AllowOverride All" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('global_vhcustom') . fs_filehandler::NewLine();
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
							
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				$line .= fs_filehandler::NewLine();
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null) {
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
			$line .= BuildStaticVhostBlock(
			    $vhostIp, $rowvhost['vh_ssl_port_in'], $rowvhost, $serveralias, $useremail,
			    ctrl_options::GetSystemOption('parking_path'),
			    'IS PARKED & HAS SSL ENABLED',
			    true, true, $rowvhost['vh_ssl_tx']
			);
			}
			$line .= fs_filehandler::NewLine();					
		/*
		 * ##################################################
		 * #
		 * # Regular or Sub domain With PHP7/PHP-FPM MOD - PHP 7+
		 * #
		 * ##################################################
		 */
		# Check
		# Domain is a regular domain or a subdomain with PHP MOD.
		} else {
		   
		    if ($rowvhost['vh_ssl_tx'] == null) {
		   
				# Create Apache Vhost directory and log folders
				# Temp (por dominio en nueva estructura)
				if ( !is_dir( $_vhpaths['tmp'] ) ) {
					fs_director::CreateDirectory( $_vhpaths['tmp'] );
				}
				# Logs
				if (!is_dir($_vhpaths['logs'])) {
					fs_director::CreateDirectory($_vhpaths['logs']);
				}
				###
				#START HERE
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $vhostPort) . ">" . fs_filehandler::NewLine();
				// Server name, alias, email settings
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				// Document root
				$line .= 'DocumentRoot ' . '"' . $RootDir . '"' . fs_filehandler::NewLine();
				// Get Package openbasedir and PHP handler enabled options
				if (ctrl_options::GetSystemOption('use_openbase') == "true") {
					if ($rowvhost['vh_obasedir_in'] <> 0) {
						// open_basedir configured in PHP-FPM pool, not in vhost
					}
				}
				

				// Logs
				if (!is_dir($_vhpaths['logs'])) {
					fs_director::CreateDirectory($_vhpaths['logs']);
				}
				$line .= 'ErrorLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-error.log" ' . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-access.log" ' . ctrl_options::GetSystemOption('access_log_format') . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . $_bwlogdir . '/' . $rowvhost['vh_name_vc'] . '-bandwidth.log" ' . ctrl_options::GetSystemOption('bandwidth_log_format') . fs_filehandler::NewLine();

				// Directory options
				$line .= '<Directory ' . $RootDir . '>' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				// FileInfo: RewriteEngine, AddType (CMS). AuthConfig: .htpasswd. Limit: <Limit>.
				// Se excluye "Options" para bloquear +ExecCGI, +SymLinks, etc. desde .htaccess de cliente.
				$line .= "    AllowOverride FileInfo AuthConfig Limit" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();

				// Pool PHP-FPM dedicado por dominio (socket individual con su php.ini)
				$line .= BuildFPMHandler($rowvhost['vh_directory_vc']) . fs_filehandler::NewLine();

				// Enable Gzip until we set this as an option , we might commenbt this too and allow manual switch
				$line .= "AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript" . fs_filehandler::NewLine();
				
				
				// Error documents:- Error pages are added automatically if they are found in the _errorpages directory
				// and if they are a valid error code, and saved in the proper format, i.e. <error_number>.html
				$line .= AppendErrorPageDirectives($_vhpaths['errorpages'], '/_errorpages/');
				// Directory indexes
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
		
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('global_vhcustom') . fs_filehandler::NewLine();
		
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
		
				// End Virtual Host Settings
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
								
				# End Virtual Host Settings
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
				
				if ($rowvhost['vh_portforward_in'] <> 0) {
					$line .= fs_filehandler::NewLine();
					$line .= BuildVhostPortForward($rowvhost['vh_name_vc'], $vhostPort, $useremail);
				}	
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ($rowvhost['vh_ssl_tx'] != null && $rowvhost['vh_ssl_port_in'] != null) {
				# Puerto 80: si el cliente FUERZA HTTPS (default) -> redirect; si NO -> servir el
				# sitio también por HTTP (mismo docroot). El :443 se genera igual en ambos casos.
				if ((int)$rowvhost['vh_forcessl_in'] !== 0) {
					# Build HTTP to HTTPS Redirect
					$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				} else {
					$line .= BuildRegularHttpVhost($rowvhost, $vhostIp, $vhostPort, $serveralias, $useremail, $RootDir, $_vhpaths, $_bwlogdir);
				}

				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= "# THIS DOMAIN HAS SSL ENABLED" . fs_filehandler::NewLine();
				
				#START HERE
				$line .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $rowvhost['vh_ssl_port_in']) . ">" . fs_filehandler::NewLine();
		
				// Server name, alias, email settings
				$line .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				if (!empty($serveralias))
					$line .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
				$line .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
				// Document root
		
				$line .= 'DocumentRoot ' . '"' . $RootDir . '"' . fs_filehandler::NewLine();
				// Get Package openbasedir and PHP handler enabled options
				if (ctrl_options::GetSystemOption('use_openbase') == "true") {
					if ($rowvhost['vh_obasedir_in'] <> 0) {
						// open_basedir configured in PHP-FPM pool, not in vhost
					}
				}
				

				// Logs
				if (!is_dir($_vhpaths['logs'])) {
					fs_director::CreateDirectory($_vhpaths['logs']);
				}
				$line .= 'ErrorLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-error.log" ' . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . $_vhpaths['logs'] . '/' . $rowvhost['vh_name_vc'] . '-access.log" ' . ctrl_options::GetSystemOption('access_log_format') . fs_filehandler::NewLine();
				$line .= 'CustomLog "' . $_bwlogdir . '/' . $rowvhost['vh_name_vc'] . '-bandwidth.log" ' . ctrl_options::GetSystemOption('bandwidth_log_format') . fs_filehandler::NewLine();

				// Directory options
				$line .= '<Directory ' . $RootDir . '>' . fs_filehandler::NewLine();
				$line .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
				// FileInfo: RewriteEngine, AddType (CMS). AuthConfig: .htpasswd. Limit: <Limit>.
				// Se excluye "Options" para bloquear +ExecCGI, +SymLinks, etc. desde .htaccess de cliente.
				$line .= "    AllowOverride FileInfo AuthConfig Limit" . fs_filehandler::NewLine();
				$line .= "    Require all granted" . fs_filehandler::NewLine();
				$line .= "</Directory>" . fs_filehandler::NewLine();

				// Pool PHP-FPM dedicado por dominio (socket individual con su php.ini)
				$line .= BuildFPMHandler($rowvhost['vh_directory_vc']) . fs_filehandler::NewLine();

				// Enable Gzip until we set this as an option , we might commenbt this too and allow manual switch
				$line .= "AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript" . fs_filehandler::NewLine();
		
				$line .= AppendErrorPageDirectives($_vhpaths['errorpages'], '/_errorpages/');
		
				// Directory indexes
				$line .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
		
				# SSL Settings
				$line .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
				$line .= $rowvhost['vh_ssl_tx'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
		
				// Global custom global vh entry
				$line .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
				$line .= ctrl_options::GetSystemOption('global_vhcustom') . fs_filehandler::NewLine();
		
				// Client custom vh entry
				$line .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
				$line .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
		
				// End Virtual Host Settings
				$line .= "</virtualhost>" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
								
				$line .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
				$line .= "################################################################" . fs_filehandler::NewLine();
			}
			$line .= fs_filehandler::NewLine();
			
		}
		/*
		 * ##################################################
		 * #
		 * # Disabled domain
		 * #
		 * ##################################################
		 */
		} else {
		# Domain not served: parking (domain suspended), suspended (account suspended), disabled (account disabled)

			if ($rowvhost['vh_ssl_tx'] == null) {

				# Load template file into vhost cofig to save
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
		$line .= BuildStaticVhostBlock(
		    $vhostIp, $vhostPort, $rowvhost, $serveralias, $useremail,
		    ctrl_options::GetSystemOption('static_dir') . $staticDir,
		    'HAS BEEN ' . strtoupper($staticDir),
		    false, false
		);
				$line .= fs_filehandler::NewLine();
			
			# If vhost SSL_TX not null create spearate <virtualhost>
			} elseif ( $rowvhost['vh_ssl_tx'] != NULL && $rowvhost['vh_ssl_port_in'] != NULL ) {
				
				# Build HTTP to HTTPS Redirect
				$line .= BuildVhostReWriteSSL($rowvhost['vh_name_vc'], $useremail);
				
				# Build Vhost SSL section
				$line .= "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
		$line .= BuildStaticVhostBlock(
		    $vhostIp, $rowvhost['vh_ssl_port_in'], $rowvhost, $serveralias, $useremail,
		    ctrl_options::GetSystemOption('static_dir') . $staticDir,
		    'HAS BEEN ' . strtoupper($staticDir) . ' & HAS SSL ENABLED',
		    false, false, $rowvhost['vh_ssl_tx']
		);
				$line .= fs_filehandler::NewLine();
			}
		}
	}
    /*
     * ##############################################################################################################
     * #
     * # Write vhost file to disk
     * #
     * ##############################################################################################################
    
	 */
	/*
    # write the vhost config file
    $vhconfigfile = ctrl_options::GetSystemOption('apache_vhost');
    if (fs_filehandler::UpdateFile($vhconfigfile, 0777, $line)) {
        # Reset Apache settings to reflect that config file has been written, until the next change.
        $time = time();
        $vsql = $zdbh->prepare("UPDATE x_settings
                                    SET so_value_tx=:time
                                    WHERE so_name_vc='apache_changed'");
        $vsql->bindParam(':time', $time);
        $vsql->execute();
        echo "Finished writting Apache Config... Now reloading Apache..." . fs_filehandler::NewLine();

        $returnValue = 0;

        $returnValue = privilege::run('apache_reload');

        echo "Apache reload " . ((0 === $returnValue ) ? "suceeded" : "failed") . "." . fs_filehandler::NewLine();
    } else {
        return false;
    }
	*/

	# Check, Write config and Restart/Reload Webserver service if apache config passes check.
	# Move current httpd-vhosts.conf file to backup incase Apache config test fails on new config.
	
	# Set values
	$vhConfigfile = ctrl_options::GetSystemOption('apache_vhost');
	$backupFileName = $vhConfigfile . "_bak_" . time();
	
	# Backup httpd-vhosts.conf file
	rename($vhConfigfile, $backupFileName);
	
	# Write to new Apache httpd-vhosts.conf file
	WriteDataToFile($vhConfigfile, $line);

	# Check Bulwark Apache httpd-vhost.conf file for errors
	echo "Checking Bulwark Apache httpd-vhost.conf config for errors..." . fs_filehandler::NewLine();

	# If Bulwark Apache httpd-vhost.conf config check returns (0) or (null) or False. Continue! success!!!!
	if ( CheckApacheVhostConfig() == FALSE ) {
		
		# Returns (FALSE) (0) or (null)
		# If Apache returned Pass config for Bulwark config httpd-vhost.conf
		unlink( $backupFileName );
		
		# Delete Bulwark Apache httpd-vhost.conf-failed config file after apache config passes syntax check
		if ( is_file( $vhConfigfile . "-failed" )) {
			unlink( $vhConfigfile . "-failed" );
		}			
			
	} elseif ( CheckApacheVhostConfig() == TRUE ) {
		
		# Returns (FAILED) (1) or NOT (null)
		# If Apache returned FAILED config for Bulwark config httpd-vhost.conf
		
		# Show error... If Bulwark Bulwark Apache httpd-vhost.conf config failed
		echo " Error: Restoring original Bulwark httpd-vhost.conf backedb up file. Check in Bulwark Panel Apache vhost config settings or httpd-vhosts.conf file for errors and retry. Something changed..." . fs_filehandler::NewLine();
		
		# If Bulwark Apache httpd-vhost.conf config failed. Copy backup Bulwark httpd-vhost_bak_time().conf.file from ( httpd-vhosts.conf )
		fs_filehandler::CopyFile( $vhConfigfile, $vhConfigfile . "-failed" );
				
		# Restore orginal Bulwark apache httpd-vhost.conf config file if failed.
		fs_filehandler::CopyFile( $backupFileName, $vhConfigfile );
		
		# Delete Backup Bulwark Apache httpd-vhost.conf_bak_time()
		unlink( $backupFileName );
	}
	
	# Restart Apache service
	RestartHttpdServices();	
}

function AppendErrorPageDirectives(string $errorpages, string $urlPrefix): string {
    $out = '';
    if (is_dir($errorpages)) {
        if ($handle = opendir($errorpages)) {
            while (($file = readdir($handle)) !== false) {
                if ($file != "." && $file != "..") {
                    $page = explode(".", $file);
                    if (!fs_director::CheckForEmptyValue(CheckErrorDocument($page[0]))) {
                        $out .= "ErrorDocument " . $page[0] . " " . $urlPrefix . $page[0] . ".html" . fs_filehandler::NewLine();
                    }
                }
            }
            closedir($handle);
        }
    }
    return $out;
}

function BuildStaticVhostBlock(
    string $vhostIp,
    $port,
    array $rowvhost,
    string $serveralias,
    string $useremail,
    string $docRoot,
    string $label,
    bool $withPhpHandler = true,
    bool $withGlobalCustom = false,
    ?string $sslTx = null
): string {
    $b  = "# DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    $b .= "# THIS DOMAIN " . $label . fs_filehandler::NewLine();
    $b .= "<virtualhost " . bulwark_vhost_addrs($vhostIp, ($rowvhost['vh_custom_ip6_vc'] ?? ''), $port) . ">" . fs_filehandler::NewLine();
    $b .= "ServerName " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    if (!empty($serveralias))
        $b .= "ServerAlias " . $serveralias . fs_filehandler::NewLine();
    $b .= "ServerAdmin " . $useremail . fs_filehandler::NewLine();
    $b .= 'DocumentRoot "' . $docRoot . '"' . fs_filehandler::NewLine();
    $b .= '<Directory "' . $docRoot . '">' . fs_filehandler::NewLine();
    $b .= "    Options +FollowSymLinks -Indexes" . fs_filehandler::NewLine();
    $b .= "    AllowOverride All" . fs_filehandler::NewLine();
    $b .= "    Require all granted" . fs_filehandler::NewLine();
    $b .= "</Directory>" . fs_filehandler::NewLine();
    if ($withPhpHandler)
        $b .= ctrl_options::GetSystemOption('php_handler') . fs_filehandler::NewLine();
    $b .= ctrl_options::GetSystemOption('dir_index') . fs_filehandler::NewLine();
    if ($sslTx !== null) {
        $b .= "# SSL settings (if any exist)" . fs_filehandler::NewLine();
        $b .= $sslTx . fs_filehandler::NewLine();
        $b .= fs_filehandler::NewLine();
    }
    if ($withGlobalCustom) {
        $b .= "# Custom Global Settings (if any exist)" . fs_filehandler::NewLine();
        $b .= ctrl_options::GetSystemOption('global_vhcustom') . fs_filehandler::NewLine();
    }
    $b .= "# Custom VH settings (if any exist)" . fs_filehandler::NewLine();
    $b .= sanitizeVhCustom($rowvhost['vh_custom_tx']) . fs_filehandler::NewLine();
    $b .= "</virtualhost>" . fs_filehandler::NewLine();
    $b .= "# END DOMAIN: " . $rowvhost['vh_name_vc'] . fs_filehandler::NewLine();
    $b .= fs_filehandler::NewLine();
    $b .= "################################################################" . fs_filehandler::NewLine();
    return $b;
}

function RestartHttpdServices() {
    global $zdbh;

	# Reset Apache settings to reflect that config file has been written, until the next change.
	$time = time();
	$vsql = $zdbh->prepare("UPDATE x_settings
								SET so_value_tx=:time
								WHERE so_name_vc='apache_changed'");
	$vsql->bindParam(':time', $time);
	$vsql->execute();
	echo "Finished writting Apache Config... Now reloading Apache..." . fs_filehandler::NewLine();

	$returnValue = 0;

	$result = privilege::run('apache_reload');
	$returnValue = $result[0];

	echo "Apache reload " . ((0 === $returnValue ) ? "suceeded" : "failed") . "." . fs_filehandler::NewLine();

}

function WriteDataToFile($panel, $line) {
	# Write the entire vhost string
	file_put_contents($panel , $line);
}

function CheckApacheVhostConfig() {
	$command = "apachectl";
	$args = "configtest";
	$ConfigReturnValue = ctrl_system::systemCommand($command, $args);

	echo "   Apache vhost Config test " . (( 0 === $ConfigReturnValue ) ? "SUCEEDED" : "FAILED") . "." . fs_filehandler::NewLine();

	return $ConfigReturnValue;
}

function CheckErrorDocument($error) {
    $errordocs = array(100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207,
        300, 301, 302, 303, 304, 305, 306, 307, 400, 401, 402,
        403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413,
        414, 415, 416, 417, 418, 419, 420, 421, 422, 423, 424,
        425, 426, 500, 501, 502, 503, 504, 505, 506, 507, 508,
        509, 510);
    if (in_array($error, $errordocs)) {
        return true;
    } else {
        return false;
    }
}

function BackupVhostConfigFile() {
    echo "Apache VHost backups are enabled... Backing up current vhost.conf to: " . ctrl_options::GetSystemOption('apache_budir') . fs_filehandler::NewLine();
    if (!is_dir(ctrl_options::GetSystemOption('apache_budir'))) {
        fs_director::CreateDirectory(ctrl_options::GetSystemOption('apache_budir'));
    }
	
	# Vhost backup file name
	$CurrBackupVhostName = ctrl_options::GetSystemOption('apache_budir') . "VHOST_BACKUP_" . time();
	
	//copy(ctrl_options::GetSystemOption('apache_vhost'), ctrl_options::GetSystemOption('apache_budir') . "VHOST_BACKUP_" . time());
    copy(ctrl_options::GetSystemOption('apache_vhost'), $CurrBackupVhostName);
	
    fs_director::SetFileSystemPermissions(ctrl_options::GetSystemOption('apache_budir') . ctrl_options::GetSystemOption('apache_vhost') . ".BU", 0777);
	
    if (ctrl_options::GetSystemOption('apache_purgebu') == strtolower("true")) {
        echo "Apache VHost purges are enabled... Purging backups older than: " . ctrl_options::GetSystemOption('apache_purge_date') . " days..." . fs_filehandler::NewLine();
        echo "[FILE][PURGE_DATE][FILE_DATE][ACTION]" . fs_filehandler::NewLine();
        $purge_date = ctrl_options::GetSystemOption('apache_purge_date');
        if ($handle = @opendir(ctrl_options::GetSystemOption('apache_budir'))) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $filetime = @filemtime(ctrl_options::GetSystemOption('apache_budir') . $file);
                    $filetime = floor((time() - $filetime) / 86400);
                    echo $file . " - " . $purge_date . " - " . $filetime . "";
                    if ($purge_date < $filetime) {
                        #delete the file
                        echo " - Deleting file..." . fs_filehandler::NewLine();
                        unlink(ctrl_options::GetSystemOption('apache_budir') . $file);
                    } else {
                        echo " - Skipping file..." . fs_filehandler::NewLine();
                    }
                }
            }
        }
        echo "Purging old backups complete..." . fs_filehandler::NewLine();
    }
    echo "Apache backups complete..." . fs_filehandler::NewLine();
}
	
/**
 * Genera el bloque SetHandler Apache para el pool PHP-FPM del dominio.
 * Cada dominio tiene su propio socket → php.ini independiente via pool.
 */
function BuildFPMHandler($vh_directory_vc) {
    $pool   = 'bulwark_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $vh_directory_vc);
    $socket = '/var/run/php-fpm/' . $pool . '.sock';
    $nl     = fs_filehandler::NewLine();
    return '<FilesMatch "\.php$">' . $nl
         . '    SetHandler "proxy:unix:' . $socket . '|fcgi://localhost/"' . $nl
         . '</FilesMatch>';
}

function TriggerApacheQuotaUsage() {
    global $zdbh;
    global $controller;
    $sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_deleted_ts IS NULL");
    $sql->execute();
    while ($rowvhost = $sql->fetch()) {
        if ($rowvhost['vh_enabled_in'] == 1 && (
            ctrl_users::CheckUserEnabled($rowvhost['vh_acc_fk']) ||
            ctrl_options::GetSystemOption('apache_allow_disabled') == strtolower("true"))) {

            //$checksize = $zdbh->query("SELECT * FROM x_bandwidth WHERE bd_month_in = " . date("Ym") . " AND bd_acc_fk = " . $rowvhost['vh_acc_fk'] . "")->fetch();

            $date = date("Ym");
            $findsize = $zdbh->prepare("SELECT * FROM x_bandwidth WHERE bd_month_in = :date AND bd_acc_fk = :acc");
            $findsize->bindParam(':date', $date);
            $findsize->bindParam(':acc', $rowvhost['vh_acc_fk']);
            $findsize->execute();
            $checksize = $findsize->fetch();

            $currentuser = ctrl_users::GetUserDetail($rowvhost['vh_acc_fk']);
            if ($checksize['bd_diskover_in'] != $checksize['bd_diskcheck_in'] && $checksize['bd_diskover_in'] == 1) {
                echo "Disk usage over quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_diskcheck_in = 1 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskcheck_in = 1 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_diskover_in'] != $checksize['bd_diskcheck_in'] && $checksize['bd_diskover_in'] == 0) {
                echo "Disk usage under quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_diskcheck_in = 0 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskcheck_in = 0 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_transover_in'] != $checksize['bd_transcheck_in'] && $checksize['bd_transover_in'] == 1) {
                echo "Bandwidth usage over quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_transcheck_in = 1 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_transcheck_in = 1 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
            if ($checksize['bd_transover_in'] != $checksize['bd_transcheck_in'] && $checksize['bd_transover_in'] == 0) {
                echo "Bandwidth usage under quota, triggering Apache..." . fs_filehandler::NewLine();
                $updateapache = $zdbh->prepare("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc ='apache_changed'");
                $updateapache->execute();

                //$updateapache = $zdbh->query("UPDATE x_bandwidth SET bd_transcheck_in = 0 WHERE bd_acc_fk =" . $rowvhost['vh_acc_fk'] . "");
                $updateapache2 = $zdbh->prepare("UPDATE x_bandwidth SET bd_transcheck_in = 0 WHERE bd_acc_fk = :acc");
                $updateapache2->bindParam(':acc', $rowvhost['vh_acc_fk']);
                $updateapache2->execute();
            }
        }
    }
}

?>
