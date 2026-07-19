<?php
/**
	* Controller for sencrypt module for bulwark version 2.0.0
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
        require_once '/usr/local/bulwark/dryden/sys/privilege.class.php';
    }
// Guard: este hook ahora corre en el ciclo HORARIO (junto a otros OnDaemonHour); evitar redeclarar.
if (!class_exists('Logger')) {
	class Logger {
		function __call($name, $arguments) {
			echo date('Y-m-d H:i:s')." [$name] " . $arguments[0] . "\n";
		}
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

# Cuenta ACME COMPARTIDA del servidor: UNA sola cuenta Let's Encrypt para todo el panel, no una por
# usuario. Motivo: LE limita a 10 CUENTAS nuevas por IP cada 3h (sin excepciones) y su guía de
# integración recomienda una única cuenta para proveedores de hosting. Antes se creaba una cuenta por
# usuario bajo su home -> al dar de alta muchos usuarios desde la única IP del servidor se agotaba el
# límite y fallaba la emisión. La clave de cuenta vive fuera de los homes (solo la usa el daemon root);
# los CERTIFICADOS siguen siendo por dominio (certlocation), solo se comparte la cuenta.
function sencrypt_shared_account_dir($staging = false) {
    # Cuenta separada para STAGING: es otra CA con otra cuenta; no debe mezclarse con producción.
    return $staging ? '/var/bulwark/ssl/sencrypt/letsencrypt-staging/' : '/var/bulwark/ssl/sencrypt/letsencrypt/';
}

# CA ACME de STAGING (pruebas). Producción es el valor por defecto de Lescript ($ca).
function sencrypt_staging_ca() {
    return 'https://acme-staging-v02.api.letsencrypt.org';
}

# ¿Modo staging activo? (ajuste le_staging). Para probar emisión/renovación/reemisión sin gastar
# los límites de producción de Let's Encrypt ni arriesgar un bloqueo de IP.
function sencrypt_is_staging() {
    return ctrl_options::GetSystemOption('le_staging') === 'true';
}

# Subcarpeta de certificados según entorno: en STAGING se emite a 'letsencrypt-staging' para NO
# sobrescribir los certs de producción (los de staging son de una raíz NO confiada) ni afectar al
# servicio en vivo. Los vhosts/panel siguen apuntando a la ruta de producción.
function sencrypt_le_subdir() {
    return sencrypt_is_staging() ? 'letsencrypt-staging' : 'letsencrypt';
}

# Bloque vh_ssl_tx que apunta al certificado WILDCARD del dominio padre (para servirlo en subdominios).
function sencrypt_wildcard_ssl_tx($username, $parentDomain) {
    $base = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/" . sencrypt_le_subdir() . "/" . $parentDomain . "/";
    $t  = "# Made from Sencrypt - wildcard - start\n\n";
    $t .= "SSLEngine On\n";
    $t .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1\n";
    $t .= "SSLHonorCipherOrder on\n";
    $t .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384\"\n";
    $t .= "SSLCertificateFile " . $base . "cert.pem\n";
    $t .= "SSLCertificateKeyFile " . $base . "private.pem\n";
    $t .= "SSLCACertificateFile " . $base . "chain.pem\n";
    $t .= "# Made from Sencrypt - wildcard - end\n";
    return $t;
}

# Cablea los SUBDOMINIOS de un dominio con wildcard para que sirvan el cert *.dominio del padre:
# les fija vh_ssl_tx -> cert del padre y los marca vh_le_wildcard_in=2 ("cubierto") para que el
# daemon NO les emita un cert propio. Se llama tras emitir/renovar el wildcard del padre.
function sencrypt_wire_wildcard_subdomains($parentVhost) {
    global $zdbh;
    $owner = ctrl_users::GetUserDetail($parentVhost['vh_acc_fk']);
    if (!$owner) return;
    $ssl = sencrypt_wildcard_ssl_tx($owner['username'], $parentVhost['vh_name_vc']);
    $q = $zdbh->prepare("SELECT vh_id_pk FROM x_vhosts WHERE vh_type_in=2 AND vh_deleted_ts IS NULL AND vh_name_vc LIKE :pat");
    $q->execute(array(':pat' => '%.' . $parentVhost['vh_name_vc']));
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:t, vh_ssl_port_in=443, vh_le_wildcard_in=2 WHERE vh_id_pk=:id")
             ->execute(array(':t' => $ssl, ':id' => $s['vh_id_pk']));
        $n++;
    }
    if ($n > 0) {
        ctrl_options::SetSystemOption('apache_changed', 'true');
        echo "   Wildcard: cableados $n subdominio(s) al cert del padre." . fs_filehandler::NewLine();
    }
}

# Offboarding / seguridad: borra TXT _acme-challenge huérfanos (>1 día) que quedaran si una validación
# DNS-01 se interrumpió (el flujo normal los borra con Dns01Cleanup). Marca las zonas afectadas para rebuild.
function sencrypt_cleanup_stale_acme() {
    global $zdbh;
    $zones = $zdbh->query("SELECT DISTINCT dn_vhost_fk FROM x_dns
        WHERE dn_type_vc='TXT' AND dn_host_vc LIKE '\\_acme-challenge%' AND dn_deleted_ts IS NULL
          AND dn_created_ts IS NOT NULL AND dn_created_ts < (UNIX_TIMESTAMP() - 86400)")->fetchAll(PDO::FETCH_COLUMN);
    if (!$zones) return;
    $zdbh->exec("UPDATE x_dns SET dn_deleted_ts=UNIX_TIMESTAMP()
        WHERE dn_type_vc='TXT' AND dn_host_vc LIKE '\\_acme-challenge%' AND dn_deleted_ts IS NULL
          AND dn_created_ts IS NOT NULL AND dn_created_ts < (UNIX_TIMESTAMP() - 86400)");
    $row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
    $ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
    foreach ($zones as $z) { if (!in_array((string)$z, $ids, true)) { $ids[] = (string)$z; } }
    $zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")->execute(array(':v' => implode(',', $ids)));
    echo "Sencrypt: limpiados " . count($zones) . " grupo(s) de TXT _acme-challenge huérfanos." . fs_filehandler::NewLine();
}

# Cachea el estado de un certificado en x_le_status (para la vista de administración): lee la fecha
# de emisión/caducidad del .pem y clasifica el estado. Si hubo error en la pasada, lo guarda.
function sencrypt_status_upsert($vhostFk, $domain, $owner, $certfile, $lastErr, $ariStart = null, $ariEnd = null, $renewAt = null) {
    global $zdbh;
    $issued = null; $expires = null; $state = 'missing';
    if (is_file($certfile)) {
        $cd = @openssl_x509_parse(@file_get_contents($certfile));
        if (is_array($cd)) {
            if (!empty($cd['validFrom_time_t'])) { $issued = (int)$cd['validFrom_time_t']; }
            if (!empty($cd['validTo_time_t']))   { $expires = (int)$cd['validTo_time_t']; }
            if ($expires !== null) {
                $days = floor(($expires - time()) / 86400);
                $state = ($days < 0) ? 'expired' : (($days <= 10) ? 'expiring' : 'valid');
            }
        }
    }
    if ($lastErr !== '') { $state = 'error'; }
    try {
        $zdbh->prepare("REPLACE INTO x_le_status
            (ls_vhost_fk, ls_domain_vc, ls_owner_vc, ls_env_vc, ls_state_vc, ls_issued_ts, ls_expires_ts, ls_last_error_tx, ls_ari_start_ts, ls_ari_end_ts, ls_renew_at_ts, ls_updated_ts)
            VALUES (:v,:d,:o,:e,:s,:i,:x,:err,:as,:ae,:ra,:u)")
            ->execute(array(
                ':v' => (int)$vhostFk, ':d' => $domain, ':o' => $owner,
                ':e' => sencrypt_is_staging() ? 'staging' : 'production',
                ':s' => $state, ':i' => $issued, ':x' => $expires,
                ':err' => ($lastErr !== '' ? $lastErr : null),
                ':as' => $ariStart, ':ae' => $ariEnd, ':ra' => $renewAt, ':u' => time(),
            ));
    } catch (\Exception $e) { /* la tabla/columnas se crean por migración; no bloquear el daemon */ }
}

function renewCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();

	$rowvhost = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_active_in = '1' AND vh_ssl_tx IS NOT NULL AND vh_ssl_port_in IS NOT NULL AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL");
	$rowvhost->execute();
	$sslVhosts = $rowvhost->fetchAll();
	$result = "";

	// Offboarding / seguridad: barrido de TXT _acme-challenge huérfanos.
	sencrypt_cleanup_stale_acme();

	// Escalado (100+ dominios): límite de EMISIONES por pasada para no superar el límite de LE de
	// 300 órdenes/cuenta/3h, y BACKOFF si LE devuelve rate-limit (se pausan emisiones hasta la marca).
	// Las que no entran esta pasada se emiten en la siguiente. Las renovaciones se reparten además por
	// ARI (timing aleatorio) y por la ventana de 30 días.
	$maxPerRun = (int)ctrl_options::GetSystemOption('le_max_per_run');
	if ($maxPerRun <= 0) { $maxPerRun = 100; }
	$issuedThisRun = 0;
	$inBackoff = time() < (int)ctrl_options::GetSystemOption('le_backoff_until');
	if ($inBackoff) {
		echo "Sencrypt: en BACKOFF por rate-limit de Let's Encrypt hasta " . gmdate('Y-m-d H:i', (int)ctrl_options::GetSystemOption('le_backoff_until')) . " UTC — se omiten emisiones esta pasada." . fs_filehandler::NewLine();
	}

	foreach($sslVhosts as $sslVhost) {
		if ($sslVhost['vh_ssl_tx'] !== false) {

			$lastErr = '';
			// Subdominio CUBIERTO por el wildcard del padre (vh_le_wildcard_in=2): sirve el cert del
			// padre (su vh_ssl_tx ya apunta ahí) y NO se le emite cert propio. Saltar.
			if ((int)($sslVhost['vh_le_wildcard_in'] ?? 0) === 2) {
				echo "Domain: " . $sslVhost['vh_name_vc'] . " — cubierto por wildcard del padre (sin cert propio)." . fs_filehandler::NewLine();
				continue;
			}
			$vhostOwner = ctrl_users::GetUserDetail($sslVhost['vh_acc_fk']);
			$_vhp_ssl = ctrl_options::GetVhostPaths($vhostOwner['username'], $sslVhost['vh_directory_vc']);
			$domainPath = $_vhp_ssl['public_html'];
			echo "Checking certificate for Client: " . $vhostOwner['username'] . " / Domain: " . $sslVhost['vh_name_vc'] . fs_filehandler::NewLine();

			// Configuration:
			$domains = $sslVhost['vh_name_vc'];
			$domains = array($domains);
			$domain = $sslVhost['vh_name_vc'];
			$webroot = $domainPath;

			# Cuenta ACME compartida del servidor (no una por usuario) — ver sencrypt_shared_account_dir(sencrypt_is_staging()).
			$accountDir = sencrypt_shared_account_dir(sencrypt_is_staging());
			# Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $vhostOwner['username'] . "/ssl/sencrypt/" . sencrypt_le_subdir() . "/" . $sslVhost['vh_name_vc'] . "/";

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
					$certfile = "$certlocation/cert.pem";
					$ariUsed = false;

					// ARI PRIMARIO (estilo Shopify): si esta habilitado y hay cert, la ventana sugerida por LE
					// es la LOGICA PRINCIPAL de renovacion -> resiliente a cambios de duracion del cert (45/6
					// dias) y responde a REVOCACION (LE acorta la ventana). Best-effort: si ARI falla o esta
					// desactivado, fallback estatico de 30 dias.
					if (ctrl_options::GetSystemOption('le_ari_enabled') === 'true' && is_file($certfile)) {
						try {
							$ariLe = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, NULL);
							if (sencrypt_is_staging()) { $ariLe->setCaUrl(sencrypt_staging_ca()); }
							$ariLe->initCommunication();
							$certID = $ariLe->getAriCertID($certfile);
							$ari = $ariLe->getRenewalInfo($certID);
							if (is_array($ari)) {
								$ariUsed = true;
								$ariStart = (int)$ari["start"]; $ariEnd = (int)$ari["end"];
								$win = max(0, $ariEnd - $ariStart);
								$renewAt = $ariStart + ($win > 0 ? (hexdec(substr(md5($certID), 0, 8)) % $win) : 0);
								if ($renewAt <= time()) {
									echo "   ARI: renovacion (ventana ".gmdate("Y-m-d H:i", $ariStart)." .. ".gmdate("Y-m-d H:i", $ariEnd)." UTC).".fs_filehandler::NewLine();
									if (!empty($ari["explanationURL"])) { echo "   ARI explanation: ".$ari["explanationURL"].fs_filehandler::NewLine(); }
									$needsgen = true;
								}
							}
						} catch (\Exception $e) { $ariUsed = false; }
					}

					// Fallback ESTATICO (ARI desactivado o no disponible): falta el cert o quedan <30 dias.
					if (!$ariUsed) {
						if (!file_exists($certfile)) {
							$needsgen = true;
						} else {
							$certdata = openssl_x509_parse(file_get_contents($certfile));
							if (is_array($certdata) && !empty($certdata["validTo_time_t"]) && time() > ($certdata["validTo_time_t"] - 86400*30)) {
								echo "   --- Renovando (regla 30 dias): ".$domain.fs_filehandler::NewLine();
								$needsgen = true;
							}
						}
					}

					// Reemision forzada desde el panel (fuerza aunque ARI/estatico no toque).
					$reissueReq = (int)($sslVhost["vh_le_reissue_ts"] ?? 0);
					if ($reissueReq > 0) {
						if (!file_exists($certfile) || filemtime($certfile) < $reissueReq) {
							echo "   Forced reissue requested via panel.".fs_filehandler::NewLine();
							$needsgen = true;
						}
					}
			}

			// Do we need to generate a certificate?
			if ($needsgen && ($inBackoff || $issuedThisRun >= $maxPerRun)) {
					echo "   Emision aplazada (".($inBackoff?"backoff rate-limit":"limite $maxPerRun/pasada").") - en la proxima pasada.".fs_filehandler::NewLine();
				} elseif ($needsgen) {
				try {
					# or without logger:
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger = NULL);
						if (sencrypt_is_staging()) { $le->setCaUrl(sencrypt_staging_ca()); }
					$le->initAccount();

					# ARI: si esta habilitado, calcular el certID del cert vigente para enviarlo como
					# `replaces` en la orden -> Let's Encrypt trata la emision como RENOVACION (exenta de rate-limits).
					$replaces = '';
					if (ctrl_options::GetSystemOption("le_ari_enabled") === "true") {
						try { $replaces = $le->getAriCertID("$certlocation/cert.pem"); } catch (\Exception $e) { $replaces = ''; }
					}

					# Check if domain is a subdomain
					$sql = "SELECT vh_type_in FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:domain AND vh_enabled_in = '1' AND vh_deleted_ts IS NULL";
					$query = $zdbh->prepare($sql);
					$query->bindParam(':userid', $sslVhost['vh_acc_fk']);
					$query->bindParam(':domain', $domain);
					$query->execute();

					# Get domain type
					$domainType = $query->fetchColumn();

					if ((int)($sslVhost['vh_le_wildcard_in'] ?? 0) === 1 && $domainType != 2) {
						// WILDCARD: un solo cert *.dominio + dominio via DNS-01 (reto _acme-challenge). Cubre todos
						// los subdominios en un cert -> esquiva el limite 50 certs/dominio/7d.
						require_once 'modules/sencrypt/code/controller.ext.php';
						echo "   WILDCARD (DNS-01): emitiendo *.".$domain." + ".$domain.fs_filehandler::NewLine();
						$le->signDomains(array('*.'.$domain, $domain), false, $replaces, 'dns-01',
							array('module_controller','Dns01Provision'), array('module_controller','Dns01Cleanup'));
							// Cablear los subdominios del dominio para que sirvan este cert wildcard.
							sencrypt_wire_wildcard_subdomains($sslVhost);
					} else if ($domainType == 2 ) {
						// Create domain without www. becuase its a subdomain
						$le->signDomains(array($domain), false, $replaces);
					} else {
						// Create a SSL with www. because its a root domain
						$le->signDomains(array($domain, 'www.'.$domain), false, $replaces);
					}
						$issuedThisRun++;

				}
				catch (\Exception $e) {
						$emsg = $e->getMessage();
						if (stripos($emsg,"ratelimited")!==false || stripos($emsg,"rate limit")!==false || stripos($emsg,"too many")!==false) {
							$inBackoff = true;
							ctrl_options::SetSystemOption("le_backoff_until", (string)(time()+3*3600));
							echo "   RATE LIMIT de Lets Encrypt: pausando emisiones 3h.".fs_filehandler::NewLine();
						} else {
							echo "ERROR: ".$emsg.fs_filehandler::NewLine();
						}
						$lastErr = $emsg;
						error_log( date("Y-m-d H:i:s")." - DOMAIN: ".$domain." - ".$emsg."\n", 3, ctrl_options::GetSystemOption("bulwark_root")."modules/sencrypt/sencrypt.log");
					}
			}

			// Aviso de caducidad: Let's Encrypt dejó de enviar emails de aviso el 4-jun-2025, así que
			// la vigilancia es responsabilidad del panel. Si tras el intento el cert sigue caducando
			// pronto (<=10 días), registrar un WARNING claro en el log para monitorización del admin.
			$certfile = "$certlocation/cert.pem";
			if (is_file($certfile)) {
				$cd = @openssl_x509_parse(@file_get_contents($certfile));
				if (is_array($cd) && !empty($cd['validTo_time_t'])) {
					$daysLeft = floor(($cd['validTo_time_t'] - time()) / 86400);
					if ($daysLeft <= 10) {
						echo "   WARNING: el certificado de " . $domain . " caduca en " . $daysLeft . " días y no se ha renovado." . fs_filehandler::NewLine();
						error_log(date('Y-m-d H:i:s') . " - EXPIRY WARNING - " . $domain . " caduca en " . $daysLeft . " dias\n", 3, ctrl_options::GetSystemOption('bulwark_root') . 'modules/sencrypt/sencrypt.log');
					}
				}
			}

			// Cachear el estado del cert para la vista de administración (x_le_status), incluida la
			// ventana ARI y el instante de renovación elegido (visibilidad estilo Shopify).
			sencrypt_status_upsert($sslVhost['vh_id_pk'], $sslVhost['vh_name_vc'], $vhostOwner['username'], "$certlocation/cert.pem", $lastErr, $ariStart, $ariEnd, $renewAt);

			echo "Domain: " . $sslVhost['vh_name_vc'] . " analyzed." . fs_filehandler::NewLine() . fs_filehandler::NewLine();
		}
	}

}

function renewPanelCertificates() {
	global $zdbh, $controller;
	$logger = new Logger();

	$result = "";

		if ((ctrl_options::GetSystemOption('panel_ssl_tx') != NULL) && (ctrl_options::GetSystemOption('bulwark_port' ) == 443 )) {

			# Renew values
			$panelOwner = "zadmin";
			$domainPath = ctrl_options::GetSystemOption('bulwark_root');
			echo "Checking certificate for Control Panel Domain: " . ctrl_options::GetSystemOption('bulwark_domain') . fs_filehandler::NewLine();

			// Configuration:
			$domains = ctrl_options::GetSystemOption('bulwark_domain');
			$domains = array($domains);
			$domain = ctrl_options::GetSystemOption('bulwark_domain');
			$webroot = $domainPath;

			# Cuenta ACME compartida del servidor (no una por usuario) — ver sencrypt_shared_account_dir(sencrypt_is_staging()).
			$accountDir = sencrypt_shared_account_dir(sencrypt_is_staging());
			# Changed to help with backup and compability
			$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $panelOwner . "/ssl/sencrypt/" . sencrypt_le_subdir() . "/" . $domain . "/";

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
			$inBackoff = time() < (int)ctrl_options::GetSystemOption("le_backoff_until");
				if ($needsgen && $inBackoff) {
					echo "   Panel: emision aplazada (backoff rate-limit) - en la proxima pasada.".fs_filehandler::NewLine();
				} elseif ($needsgen) {
				try {
					# or without logger:
					$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $webroot, $logger = NULL);
						if (sencrypt_is_staging()) { $le->setCaUrl(sencrypt_staging_ca()); }
					$le->initAccount();

					// ARI: `replaces` para exencion de rate-limit en la renovacion del panel (gated).
					$replaces = '';
					if (ctrl_options::GetSystemOption("le_ari_enabled") === "true") {
						try { $replaces = $le->getAriCertID("$certlocation/cert.pem"); } catch (\Exception $e) { $replaces = ''; }
					}
					// Create panel domain cert (only the panel domain, no www)
					$le->signDomains(array($domain), false, $replaces);

					// After successful renewal, update panel_ssl_tx in DB to point to new cert
					$newCert = $certlocation . "cert.pem";
					$newKey  = $certlocation . "private.pem";
					if (!sencrypt_is_staging() && file_exists($newCert) && file_exists($newKey)) {
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
						$emsg = $e->getMessage();
						if (stripos($emsg,"ratelimited")!==false || stripos($emsg,"rate limit")!==false || stripos($emsg,"too many")!==false) {
							ctrl_options::SetSystemOption("le_backoff_until", (string)(time()+3*3600));
							echo "   RATE LIMIT de Lets Encrypt (panel): pausando emisiones 3h.".fs_filehandler::NewLine();
						} else {
							echo "ERROR: ".$emsg.fs_filehandler::NewLine();
						}
						error_log( date("Y-m-d H:i:s")." - PANEL DOMAIN: ".$domain." - ".$emsg."\n", 3, ctrl_options::GetSystemOption("bulwark_root")."modules/sencrypt/sencrypt.log");
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
