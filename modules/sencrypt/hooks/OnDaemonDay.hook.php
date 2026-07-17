<?php
/**
	* Controller for sencrypt module for sentora version 2.0.0
	* Version : 3.0.1
	* Author : TGates
	* Additional work by Diablo925, Jettaman
 */

// Lescript automatic updating script.
//
// This is an example of how Lescript can be used to automatically update
// expiring certificates.
//
// This code is based on FreePBX's LetsEncrypt integration
//
// Copyright (c) 2016 Rob Thomas <rthomas@sangoma.com>
// Licence:  AGPLv3.
//
// In addition, Stanislav Humplik <sh@analogic.cz> is explicitly granted permission
// to relicence this code under the open source licence of their choice.


# for LEscript you can use any logger according to Psr\Log\LoggerInterface
    if (!class_exists('privilege')) {
        require_once '/usr/local/sentora/dryden/sys/privilege.class.php';
    }
class Logger {
	function __call($name, $arguments) {
		echo date('Y-m-d H:i:s')." [$name] " . $arguments[0] . "\n";
	}
}
$logger = new Logger();

echo fs_filehandler::NewLine() . "START Sencrypt Manager SSL Renewal Hook." . fs_filehandler::NewLine();
if (ui_module::CheckModuleEnabled('Sencrypt SSL')) {

    echo "Sencrypt Manager module ENABLED..." . fs_filehandler::NewLine();

	if ( ctrl_options::GetSystemOption('panel_ssl_tx') != null) {

		echo fs_filehandler::NewLine() . "RENEWING Control Panel certificates..." . fs_filehandler::NewLine();
			# Run renew panel cert function
			renewPanelCertificates();

		echo fs_filehandler::NewLine()."RENEWING Control Panel certificates completed." . fs_filehandler::NewLine();
	}

	echo fs_filehandler::NewLine() . "RENEWING client certificates..." . fs_filehandler::NewLine();
		# Run renew cert function
		renewCertificates();

	echo "RENEWING client certificates completed." . fs_filehandler::NewLine();

	# El reload de Apache lo gestiona apache_admin/OnDaemonRun al detectar apache_changed='true'.
	# No llamamos RestartHttpdServicesForSSL() aquí para evitar el doble reload (FIX-60).

} else {

    echo "Sencrypt Manager module DISABLED...nothing to do." . fs_filehandler::NewLine();
}

echo "END Sencrypt Manager SSL Renewal Hook." . fs_filehandler::NewLine();

# Start functions here
function renewCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();

	$rowvhost = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_active_in = '1' AND vh_ssl_tx IS NOT NULL AND vh_ssl_port_in IS NOT NULL AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL");
	$rowvhost->execute();
	$sslVhosts = $rowvhost->fetchAll();
	$result = "";

	foreach($sslVhosts as $sslVhost) {
		if ($sslVhost['vh_ssl_tx'] !== false) {

			$vhostOwner = ctrl_users::GetUserDetail($sslVhost['vh_acc_fk']);
			$_vhp_ssl = ctrl_options::GetVhostPaths($vhostOwner['username'], $sslVhost['vh_directory_vc']);
			$domainPath = $_vhp_ssl['public_html'];
			echo "Checking certificate for Client: " . $vhostOwner['username'] . " / Domain: " . $sslVhost['vh_name_vc'] . fs_filehandler::NewLine();

			// Configuration:
			$domains = $sslVhost['vh_name_vc'];
			$domains = array($domains);
			$domain = $sslVhost['vh_name_vc'];
			$webroot = $domainPath;

			$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/ssl/sencrypt/letsencrypt/";
			# Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/ssl/sencrypt/letsencrypt/" . $sslVhost['vh_name_vc'] . "/";

			# Require Lescript for renewal of SSL certs
			require_once 'modules/sencrypt/code/Lescript.php';

			// Always use UTC
			date_default_timezone_set("UTC");

			// Do we need to create or upgrade our cert? Assume no to start with.
			$needsgen = false;

			// Doble pila / IP dedicada: el dominio es válido si resuelve a server_ip, a su IPv4
			// dedicada (vh_custom_ip_vc) o a su IPv6 dedicada (vh_custom_ip6_vc).
			$acceptIPs = array(
				ctrl_options::GetSystemOption('server_ip'),
				ctrl_options::GetSystemOption('server_ip6'),
				$sslVhost['vh_custom_ip_vc'] ?? '',
				$sslVhost['vh_custom_ip6_vc'] ?? '',
			);

			# Check if Domain is LIVE and Pointing to this server using local DNS
			if (!checkDNSIsLive($domain, $acceptIPs)) {
				echo "   DNS is not LIVE or POINTING to server. SKIPPING." . fs_filehandler::NewLine();

			} else {
				// Do we HAVE a certificate for all our domains?
				foreach ($domains as $d) {
					$certfile = "$certlocation/cert.pem";
					if (!file_exists($certfile)) {
						// We don't have a cert, so we need to request one.
						$needsgen = true;
					} else {
						// We DO have a certificate.
						$certdata = openssl_x509_parse(file_get_contents($certfile));
						echo "   Checking certificate for renewal: " . $d . "..." . fs_filehandler::NewLine();
						// If it expires in less than a month, we want to renew it.
						$renewafter = $certdata['validTo_time_t']-(86400*30);

						if (time() > $renewafter) {
							// Less than a month left, we need to renew.
							echo "   --- Renewing certificate : " . $d . " for ... 90 Days" . fs_filehandler::NewLine();
							$needsgen = true;
						}
					}
				}
				// Reemisión forzada desde el panel (botón "Reemitir"): si hay marca vh_le_reissue_ts
				// y el cert es anterior a esa marca (o falta), forzar emisión saltándose los 30 días.
				// El cooldown de 48h que respeta el límite de LE lo aplica el panel (doForceReissue).
				$reissueReq = (int)($sslVhost['vh_le_reissue_ts'] ?? 0);
				if ($reissueReq > 0) {
					$certfile = "$certlocation/cert.pem";
					if (!file_exists($certfile) || filemtime($certfile) < $reissueReq) {
						echo "   Forced reissue requested via panel — forcing renewal." . fs_filehandler::NewLine();
						$needsgen = true;
					}
				}
			}

			// Do we need to generate a certificate?
			if ($needsgen) {
				try {
					# or without logger:
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger = NULL);
					$le->initAccount();

					# Check if domain is a subdomain
					$sql = "SELECT vh_type_in FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:domain AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL";
					$query = $zdbh->prepare($sql);
					$query->bindParam(':userid', $sslVhost['vh_acc_fk']);
					$query->bindParam(':domain', $domain);
					$query->execute();

					# Get domain type
					$domainType = $query->fetchColumn();

					if ($domainType == 2 ) {
						// Create domain without www. becuase its a subdomain
						$le->signDomains(array($domain));
					} else {
						// Create a SSL with www. because its a root domain
						$le->signDomains(array($domain, 'www.'.$domain));
					}

				}
				catch (\Exception $e) {
					echo "ERROR: " . $e->getMessage() . fs_filehandler::NewLine();
					# Log error but continue with remaining domains (do NOT exit)
					error_log( date('Y-m-d H:i:s') . " - DOMAIN: " . $domain . " - " . $e->getMessage() . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
				}
			}

			echo "Domain: " . $sslVhost['vh_name_vc'] . " analyzed." . fs_filehandler::NewLine() . fs_filehandler::NewLine();
		}
	}

}

function renewPanelCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();

	$result = "";

		if ((ctrl_options::GetSystemOption('panel_ssl_tx') != NULL) && (ctrl_options::GetSystemOption('sentora_port' ) == 443 )) {

			# Renew values
			$panelOwner = "zadmin";
			$domainPath = ctrl_options::GetSystemOption('sentora_root');
			echo "Checking certificate for Control Panel Domain: " . ctrl_options::GetSystemOption('sentora_domain') . fs_filehandler::NewLine();

			// Configuration:
			$domains = ctrl_options::GetSystemOption('sentora_domain');
			$domains = array($domains);
			$domain = ctrl_options::GetSystemOption('sentora_domain');
			$webroot = $domainPath;

			$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $panelOwner . "/ssl/sencrypt/letsencrypt/";
			# Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $panelOwner . "/ssl/sencrypt/letsencrypt/" . $domain . "/";

			# Require Lescript for renewal of SSL certs
			require_once 'modules/sencrypt/code/Lescript.php';

			// Always use UTC
			date_default_timezone_set("UTC");

			// Do we need to create or upgrade our cert? Assume no to start with.
			$needsgen = false;

			// El panel vive en la IP primaria (server_ip) y, en doble pila, en server_ip6.
			$acceptIPs = array(
				ctrl_options::GetSystemOption('server_ip'),
				ctrl_options::GetSystemOption('server_ip6'),
			);

			# Check if Domain is LIVE and Pointing to this server using local DNS
			if (!checkDNSIsLive($domain, $acceptIPs)) {
				echo "   DNS is not LIVE or POINTING to server. SKIPPING." . fs_filehandler::NewLine();

			} else {
				// Do we HAVE a certificate for all our domains?
				$certfile = "$certlocation/cert.pem";
				if (!file_exists($certfile)) {
					// Cert is autofirmado or third-party — skip auto-renewal
					echo "   No Let's Encrypt cert found at $certfile. Skipping auto-renewal (autofirmado or commercial cert)." . fs_filehandler::NewLine();
				} else {
					// We DO have a Let's Encrypt certificate.
					$certdata = openssl_x509_parse(file_get_contents($certfile));
					echo "   Checking certificate for renewal: " . $domain . "..." . fs_filehandler::NewLine();
					// If it expires in less than a month, we want to renew it.
					$renewafter = $certdata['validTo_time_t']-(86400*30);

					if (time() > $renewafter) {
						// Less than a month left, we need to renew.
						echo "   --- Renewing certificate : " . $domain . " for ... 90 Days" . fs_filehandler::NewLine();
						$needsgen = true;
					} else {
						echo "   Certificate still valid for more than 30 days. No renewal needed." . fs_filehandler::NewLine();
					}
				}
			}

			// Do we need to generate a certificate?
			if ($needsgen) {
				try {
					# or without logger:
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger = NULL);
					$le->initAccount();

					// Create panel domain cert (only the panel domain, no www)
					$le->signDomains(array($domain));

					// After successful renewal, update panel_ssl_tx in DB to point to new cert
					$newCert = $certlocation . "cert.pem";
					$newKey  = $certlocation . "private.pem";
					if (file_exists($newCert) && file_exists($newKey)) {
						$ssl_tx  = "SSLEngine On\n";
						$ssl_tx .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1\n";
						$ssl_tx .= "SSLCipherSuite ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384\n";
						$ssl_tx .= "SSLCertificateFile " . $newCert . "\n";
						$ssl_tx .= "SSLCertificateKeyFile " . $newKey . "\n";
						$upd = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='panel_ssl_tx'");
						$upd->bindValue(':v', $ssl_tx);
						$upd->execute();
						$upd2 = $zdbh->prepare("UPDATE x_settings SET so_value_tx='true' WHERE so_name_vc='apache_changed'");
						$upd2->execute();
						echo "   panel_ssl_tx updated in DB." . fs_filehandler::NewLine();
					}

				}
				catch (\Exception $e) {
					echo "ERROR: " . $e->getMessage() . fs_filehandler::NewLine();
					# Log error but do NOT exit — daemon must continue
					error_log( date('Y-m-d H:i:s') . " - PANEL DOMAIN: " . $domain . " - " . $e->getMessage() . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('sentora_root') . 'modules/sencrypt/sencrypt.log');
				}
			}

			echo "Control Panel Domain: " . $domain . " analyzed." . fs_filehandler::NewLine();
		}

}

function RestartHttpdServicesForSSL() {

    global $zdbh;

	echo "Finished Renewing Sencrypt SSL's... Now reloading Apache..." . fs_filehandler::NewLine();

	$result      = privilege::run('apache_reload');
	$returnValue = $result[0]; // privilege::run devuelve [$exitCode, $output]

	echo "Apache reload " . ((0 === $returnValue) ? "suceeded" : "failed") . "." . fs_filehandler::NewLine();

}

// Verificar que el dominio resuelve a UNA de las IP que este servidor sirve para él, usando DNS
// local (sin servicios externos). Doble pila: acepta si el registro A apunta a una IPv4 nuestra
// (server_ip o la IPv4 dedicada del dominio) O si el registro AAAA apunta a nuestra IPv6 dedicada.
// $acceptIPs: array de IP (v4 y/o v6) válidas para este dominio. Comparación por inet_pton para
// tolerar diferencias de formato (p.ej. IPv6 comprimida vs expandida).
function checkDNSIsLive($domain, $acceptIPs) {
	if (!is_array($acceptIPs)) { $acceptIPs = array($acceptIPs); }
	$accept = array();
	foreach ($acceptIPs as $ip) {
		$ip = trim((string)$ip);
		if ($ip === '') continue;
		$p = @inet_pton($ip);
		if ($p !== false) { $accept[] = $p; }
	}
	if (empty($accept)) { return false; }
	// A (IPv4)
	$a = @dns_get_record($domain, DNS_A);
	if (!empty($a)) {
		foreach ($a as $r) {
			if (isset($r['ip']) && ($pp = @inet_pton($r['ip'])) !== false && in_array($pp, $accept, true)) { return true; }
		}
	}
	// AAAA (IPv6)
	$aaaa = @dns_get_record($domain, DNS_AAAA);
	if (!empty($aaaa)) {
		foreach ($aaaa as $r) {
			if (isset($r['ipv6']) && ($pp = @inet_pton($r['ipv6'])) !== false && in_array($pp, $accept, true)) { return true; }
		}
	}
	return false;
}

?>
