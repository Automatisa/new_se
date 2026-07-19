<?php
/**
	* Controller for sencrypt module for bulwark version 2.0.0
	* Version : 3.0.1
	* Author : TGates
	* Additional work by Diablo925, Jettaman
 */

# search [(lecrypt) for LetsEncrypt code changes: tg] [make ssl: LeClient or LePanel]

# for LEscript you can use any logger according to Psr\Log\LoggerInterface
class Logger {
	function __call($name, $arguments) {
		echo date('Y-m-d H:i:s')." [$name] " . $arguments[0] . "\n";
	}
}
//$logger = new Logger();

class module_controller extends ctrl_module {
	
	static $okletsencrypt;
	static $okselfsigned;
	static $okthirdparty;
	static $delok;
	static $error;
	static $dnsInvalid;
	static $modReqsError;
	static $portReqsError;
	static $revokecert;
	static $keyadd;
	static $certFailed;
	//static $loggererror;

    /* Load CSS and JS files */
    static function getInit() {
        global $controller;
		# load module spcific style sheet
        $line = '<link rel="stylesheet" type="text/css" href="modules/' . $controller->GetControllerRequest('URL', 'module') . '/assets/sencrypt.css">';
        return $line;
    }

	static function getCheckModReq() {
		# Post message if PHP requirments are not met 
		if (!defined("PHP_VERSION_ID") || PHP_VERSION_ID < 50500 || !extension_loaded('openssl') || !extension_loaded('curl')) {
			self::$modReqsError = true;
		}
	}

	static function getCheckPortReq() {
		if (!sys_monitoring::LocalPortStatus(443)) {
			self::$portReqsError = true;
		}
	}

# Module & Dispaly stuff - START
	static function getShowViewCerts() {
		if (isset($_GET['ShowPanel']) == true) {
			if ($_GET['ShowPanel'] == 'viewcerts') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "active";
				return $showActiveTab;
			}
		}
	}

	static function getShowCreateCerts() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'createcerts') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	static function getShowLetsEncryptTab() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'letsencrypt') {
				$showPanel = "block";
				return $showPanel;
			}
		} else {
			$showPanel = "none";
			return $showPanel;
		}
	}

	static function getShowThirdPartyTab() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'third-party' )  {
				$showPanel = "block";
				return $showPanel;
			}		
		} else {
			$showPanel = "none";
			return $showPanel;
		}
	}

	static function getShowLetsencryptActive() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'letsencrypt') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	static function getShowThird_partyActive() {
		if (isset($_GET['ShowPanel']) == true ) {
			if ($_GET['ShowPanel'] == 'third-party') {
				$showActiveTab = "active";
				return $showActiveTab;
			} else {
				$showActiveTab = "none";
				return $showActiveTab;
			}
		}
	}

	static function getAdmin() {
		$user = ctrl_users::GetUserDetail();
		return ((int)$user['usergroupid'] === ctrl_groups::GROUP_ADMIN);
	}

	// ===== DNS-01: provisión/limpieza del reto _acme-challenge (necesario para WILDCARDS) =========
	// Reconstrucción SÍNCRONA de la zona (privilege dns_rebuild) + espera de propagación en el BIND
	// local, para que el reto DNS-01 de Let's Encrypt esté servido antes de pedir la validación.

	// Zona (dominio registrado, vh_type_in=1) que es sufijo más largo de $base. Devuelve fila o null.
	private static function Dns01FindZone($base) {
		global $zdbh;
		$best = null; $bestLen = -1;
		foreach ($zdbh->query("SELECT vh_id_pk, vh_name_vc FROM x_vhosts WHERE vh_type_in=1 AND vh_deleted_ts IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$n = strtolower($r['vh_name_vc']);
			if ($base === $n || substr($base, -(strlen($n) + 1)) === '.' . $n) {
				if (strlen($n) > $bestLen) { $bestLen = strlen($n); $best = $r; }
			}
		}
		return $best;
	}

	private static function Dns01Label($recordName, $zoneName) {
		if ($recordName === '_acme-challenge.' . $zoneName) return '_acme-challenge';
		if (substr($recordName, -(strlen($zoneName) + 1)) === '.' . $zoneName) {
			return substr($recordName, 0, strlen($recordName) - strlen($zoneName) - 1);
		}
		return null;
	}

	private static function Dns01MarkRebuild($vhostId) {
		global $zdbh;
		$row = $zdbh->query("SELECT so_value_tx FROM x_settings WHERE so_name_vc='dns_hasupdates'")->fetch();
		$ids = array_filter(explode(',', (string)($row['so_value_tx'] ?? '')), 'strlen');
		if (!in_array((string)$vhostId, $ids, true)) { $ids[] = (string)$vhostId; }
		$zdbh->prepare("UPDATE x_settings SET so_value_tx=:v WHERE so_name_vc='dns_hasupdates'")->execute([':v' => implode(',', $ids)]);
	}

	// Sondea el BIND local hasta que el TXT $recordName contenga (o deje de contener) $value.
	private static function Dns01WaitTxt($recordName, $value, $want, $timeout = 60) {
		$deadline = time() + $timeout;
		do {
			$out = array();
			@exec('/usr/local/bin/dig +short @127.0.0.1 TXT ' . escapeshellarg($recordName) . ' 2>/dev/null', $out);
			$found = false;
			foreach ($out as $line) { if (strpos($line, $value) !== false) { $found = true; break; } }
			if ($found === $want) return true;
			sleep(3);
		} while (time() < $deadline);
		return false;
	}

	// Callback de provisión para Lescript::signDomains(dns-01). Crea/actualiza el TXT y espera propagación.
	static function Dns01Provision($recordName, $value) {
		global $zdbh;
		if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
		$recordName = strtolower(rtrim($recordName, '.'));
		if (strpos($recordName, '_acme-challenge.') !== 0) return false;
		$base = substr($recordName, strlen('_acme-challenge.'));
		$zone = self::Dns01FindZone($base);
		if (!$zone) { error_log("dns01: sin zona para $base"); return false; }
		$zoneName = strtolower($zone['vh_name_vc']);
		$label = self::Dns01Label($recordName, $zoneName);
		if ($label === null) return false;
		$acc = (int)$zdbh->query("SELECT dn_acc_fk FROM x_dns WHERE dn_vhost_fk=" . (int)$zone['vh_id_pk'] . " LIMIT 1")->fetchColumn();
		$ex = $zdbh->prepare("SELECT dn_id_pk FROM x_dns WHERE dn_vhost_fk=:v AND dn_type_vc='TXT' AND dn_host_vc=:h AND dn_deleted_ts IS NULL LIMIT 1");
		$ex->execute([':v' => $zone['vh_id_pk'], ':h' => $label]);
		if ($id = $ex->fetchColumn()) {
			$zdbh->prepare("UPDATE x_dns SET dn_target_vc=:t WHERE dn_id_pk=:id")->execute([':t' => $value, ':id' => $id]);
		} else {
			$zdbh->prepare("INSERT INTO x_dns (dn_acc_fk,dn_name_vc,dn_vhost_fk,dn_type_vc,dn_host_vc,dn_ttl_in,dn_target_vc,dn_created_ts) VALUES (:a,:n,:v,'TXT',:h,60,:t,UNIX_TIMESTAMP())")
			     ->execute([':a' => $acc, ':n' => $zoneName, ':v' => $zone['vh_id_pk'], ':h' => $label, ':t' => $value]);
		}
		self::Dns01MarkRebuild($zone['vh_id_pk']);
		try { privilege::run('dns_rebuild'); } catch (\Exception $e) { error_log("dns01 rebuild: " . $e->getMessage()); }
		return self::Dns01WaitTxt($recordName, $value, true, 60);
	}

	// Callback de limpieza para Lescript::signDomains(dns-01). Borra el TXT y reconstruye la zona.
	static function Dns01Cleanup($recordName, $value) {
		global $zdbh;
		if (!class_exists('privilege')) { require_once '/usr/local/bulwark/dryden/sys/privilege.class.php'; }
		$recordName = strtolower(rtrim($recordName, '.'));
		if (strpos($recordName, '_acme-challenge.') !== 0) return false;
		$base = substr($recordName, strlen('_acme-challenge.'));
		$zone = self::Dns01FindZone($base);
		if (!$zone) return false;
		$label = self::Dns01Label($recordName, strtolower($zone['vh_name_vc']));
		if ($label === null) return false;
		$zdbh->prepare("UPDATE x_dns SET dn_deleted_ts=UNIX_TIMESTAMP() WHERE dn_vhost_fk=:v AND dn_type_vc='TXT' AND dn_host_vc=:h AND dn_deleted_ts IS NULL")
		     ->execute([':v' => $zone['vh_id_pk'], ':h' => $label]);
		self::Dns01MarkRebuild($zone['vh_id_pk']);
		try { privilege::run('dns_rebuild'); } catch (\Exception $e) {}
		return true;
	}

	static function getList_of_Panel_Domains() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_Panel_domains($currentuser['userid']);
	}	
	
	# panel ssl show - START
	static function getList_of_Active_Panel_SSL() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_Active_Panel_SSL_Domains($currentuser['userid']);
	}
	
	static function Show_Active_Panel_SSL_Domains() {
		global $zdbh, $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$panelCertPath = ctrl_options::GetSystemOption('hosted_dir');
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		
		# Check if Panel ssl folder exists - This file should have been created during install.
		# Check if cert exist or not
		if ( is_file( $panelCertPath . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panelDomain . "/cert.pem" ) ) {	
			$panelDeleteButton = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=DeletePanelSSL" method="post">'
			. runtime_csfr::Token() .
			'<input type="hidden" name="inName" value="'. htmlspecialchars($panelDomain, ENT_QUOTES, 'UTF-8').'">
			<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeletePanelSSL" value="inDeletePanelSSL"><i class="bi bi-trash me-1"></i>' . ui_language::translate("Delete") . '</button>
			</form>';
			$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panelDomain . "/cert.pem"));
			$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
			$now = time();
			$your_date = strtotime("$validTo");
			$datediff = $your_date - $now;
			$panelday = floor($datediff / (60 * 60 * 24));
			$reNewDay = $panelday - 30;
			$sslvendor = "Lets Encrypt";
			
			if($panelday <= "-1700") {
				$paneldays = ui_language::translate("Not initialized yet");
				} else {
				$paneldays = ui_language::translate("Expiry in") . ' ' . $panelday . ' ' . ui_language::translate("days") . ' - ' . ui_language::translate("Auto-renewal in") . ' ' . $reNewDay . ' ' . ui_language::translate("days");
			}
			
			# Revoke button just incase its needed
			$panelRevokeButton = '<form action="./?module=sencrypt&action=RevokePanelSSL" method="post">'
				. runtime_csfr::Token() .
				'<input type="hidden" name="inDomain" value="'. htmlspecialchars($panelDomain, ENT_QUOTES, 'UTF-8').'">
				<button class="button-loader btn btn-danger" type="submit" id="button" name="inRevokeSSL" id="inRevokePanelSSL" value="inRevokePanelSSL"><i class="bi bi-slash-circle me-1"></i>' . ui_language::translate("Revoke") . '</button>
			</form>';
		
			$panelres[] = array('Active_Panel_Domain' => $panelDomain, 'Active_Panel_Provider' => $sslvendor, 'Active_Panel_Days' =>  $paneldays, 'Active_Panel_Button' => $panelDeleteButton,  'Active_Panel_Revoke' => $panelRevokeButton);
		
		# If third party ssl show
		} elseif ( is_dir( $panelCertPath . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panelDomain . "/" ) ) {
						
			$panelDeleteButton = '<form action="./?module=sencrypt&ShowPanel=third_party&action=TPDelete" method="post">'
			. runtime_csfr::Token() .
			'<input type="hidden" name="inName" value="'. htmlspecialchars($panelDomain, ENT_QUOTES, 'UTF-8').'">
			<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeletePanelSSL" value="inDeletePanelSSL"><i class="bi bi-trash me-1"></i>' . ui_language::translate("Delete") . '</button>
			</form>';
			$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panelDomain . "/cert.pem"));
			$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
			$now = time();
			$your_date = strtotime("$validTo");
			$datediff = $your_date - $now;
			$panelday = floor($datediff / (60 * 60 * 24));
			$reNewDay = $panelday - 30;
			$sslvendor = "Third-Party";
			
			if($panelday <= "-1700") {
				$paneldays = ui_language::translate("Not initialized yet");
			} else {
				$paneldays = ui_language::translate("Expiry in") . ' ' . $panelday . ' ' . ui_language::translate("days") . ".";
			}
		
			$panelres[] = array('Active_Panel_Domain' => $panelDomain, 'Active_Panel_Provider' => $sslvendor, 'Active_Panel_Days' =>  $paneldays, 'Active_Panel_Button' => $panelDeleteButton, 'Active_Panel_Revoke' => NULL);
		
		} else {

			$panelres[] = array('Active_Panel_Domain' => ui_language::translate("No Active Panel Domain Certificates"), 'Active_Panel_Provider' => NULL, 'Active_Panel_Days' =>  NULL, 'Active_Panel_Button' => NULL, 'Active_Panel_Revoke' => NULL);
			
		}
		return $panelres;
		
	}

	static function Show_Panel_Domains() {
		global $zdbh, $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$panelCertPath = ctrl_options::GetSystemOption('hosted_dir');
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		
				
		# Check if cert exist or not
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/". $panelDomain ."/") ) {

			# Check if ssl exists else where
			if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/third_party/". $panelDomain ."/") ) {
				# do nothing cert exists
				$panelbutton = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=MakePanelSSL" method="post">'
				. runtime_csfr::Token() .
				'<input type="hidden" name="inDomain" value="'. htmlspecialchars($panelDomain, ENT_QUOTES, 'UTF-8').'">
				<button class="button-loader btn btn-primary" type="submit" id="button" name="in" id="inMakePanelSSL" value="inMakePanelSSL"><i class="bi bi-shield-lock me-1"></i>' . ui_language::translate("Encrypt") . '</button>
				</form>';
				$paneldays = "";
				
				$panelres[] = array('Panel_Domain' => $panelDomain, 'Panel_Button' => $panelbutton);
				
				$panelresult = $panelres;
			}
			
		} else {
	
			$panelresult = false;

		}
		
		return $panelresult;
	}
	# panel ssl show - END

	# domain ssl show -START
	static function getList_of_domains() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_list_of_domains($currentuser['userid']);
	}

	static function Show_list_of_domains() {
		global $zdbh, $controller;
        $currentuser = ctrl_users::GetUserDetail();
		
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
				# Check if folder ssl exists
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/") ) {
					fs_director::CreateDirectory( ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/" );
				}
				
				# Check if cert exist or not
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/letsencrypt/". $rowdomains['vh_name_vc'] ."/") ) {

					# Check if ssl exists else where
					if (is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] ."/ssl/sencrypt/third_party/". $rowdomains['vh_name_vc'] ."/") ) {

						# Do nothing

					} else {

						$button = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=MakeSSL" method="post">'
							. runtime_csfr::Token() .
							'<input type="hidden" name="inDomain" value="'.htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8').'">
							<button class="button-loader btn btn-primary" type="submit" id="button" name="in" id="inMakeSSL" value="inMakeSSL"><i class="bi bi-shield-lock me-1"></i>' . ui_language::translate("Encrypt") . '</button>
						</form>';
						
						$res[] = array('Vh_Domain' => $rowdomains['vh_name_vc'], 'Vh_Button' => $button);
					}
				}
			}

			if (!$res) {
				$res[] = array('Vh_Domain' => ui_language::translate("All domains have certificates."), 'Vh_Button' => NULL);
			}

		} else {	
		
			$res = array('Vh_Domain' => ui_language::translate("You have no available domains. Add one to continue."), 'Vh_Button' => NULL);
	
		}
		return $res;
	}

	static function getList_of_active_domains_ssl() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::Show_list_of_active_domain_ssl($currentuser['userid']);
	}

	# Botón "Wildcard": marca el dominio para emitir un cert *.dominio (+ dominio) por DNS-01. Solo
	# dominios raíz (no subdominios). Un cert cubre todos los subdominios (esquiva 50 certs/dominio/7d).
	static function WildcardButton($rowdomains) {
		if ((int)($rowdomains['vh_type_in'] ?? 1) === 2) return ''; // no en subdominios
		$on = (int)($rowdomains['vh_le_wildcard_in'] ?? 0) === 1;
		return '<form action="./?module=sencrypt&action=SetWildcard" method="post" style="display:inline">'
			. runtime_csfr::Token()
			. '<input type="hidden" name="inDomain" value="' . htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8') . '">'
			. '<input type="hidden" name="inOn" value="' . ($on ? '0' : '1') . '">'
			. '<button class="button-loader btn ' . ($on ? 'btn-success' : 'btn-outline-secondary') . '" type="submit">'
			. '<i class="bi bi-asterisk me-1"></i>' . ($on ? 'Wildcard: ON' : 'Wildcard') . '</button>'
			. '</form>';
	}

	# Activa/desactiva wildcard en un dominio del PROPIO usuario (anti-IDOR). Al activar, marca reemisión
	# (con las mismas guardas que doForceReissue: cooldown + DNS) para que el daemon emita el wildcard.
	static function doSetWildcard() {
		global $zdbh, $controller;
		runtime_csfr::Protect();
		$cu     = ctrl_users::GetUserDetail();
		$domain = (string)$controller->GetControllerRequest('FORM', 'inDomain');
		$on     = $controller->GetControllerRequest('FORM', 'inOn') ? 1 : 0;
		$q = $zdbh->prepare("SELECT vh_id_pk, vh_le_reissue_ts, vh_custom_ip_vc, vh_custom_ip6_vc FROM x_vhosts
		                     WHERE vh_name_vc=:d AND vh_acc_fk=:u AND vh_type_in=1 AND vh_deleted_ts IS NULL");
		$q->execute(array(':d' => $domain, ':u' => (int)$cu['userid']));
		$vh = $q->fetch(PDO::FETCH_ASSOC);
		if (!$vh) {
			$_SESSION['sencrypt_flash'] = array('err', ui_language::translate('Domain not valid.'));
		} else {
			$zdbh->prepare("UPDATE x_vhosts SET vh_le_wildcard_in=:w WHERE vh_id_pk=:id")->execute(array(':w' => $on, ':id' => (int)$vh['vh_id_pk']));
			if ($on) {
				$last = (int)($vh['vh_le_reissue_ts'] ?? 0);
				if ($last > 0 && (time() - $last) < 48 * 3600) {
					$_SESSION['sencrypt_flash'] = array('ok', 'Wildcard activado para ' . htmlspecialchars($domain) . '. (Ya hubo una (re)emisión reciente; se emitirá pasado el cooldown de 48h de Let\'s Encrypt.)');
				} elseif (!self::domainResolvesToUs($domain, $vh['vh_custom_ip_vc'] ?? '', $vh['vh_custom_ip6_vc'] ?? '')) {
					$_SESSION['sencrypt_flash'] = array('ok', 'Wildcard activado para ' . htmlspecialchars($domain) . '. Ajusta el DNS del dominio a este servidor para que el daemon lo emita (validación DNS-01).');
				} else {
					$zdbh->prepare("UPDATE x_vhosts SET vh_le_reissue_ts=:t WHERE vh_id_pk=:id")->execute(array(':t' => time(), ':id' => (int)$vh['vh_id_pk']));
					$_SESSION['sencrypt_flash'] = array('ok', 'Wildcard activado para ' . htmlspecialchars($domain) . '. Se emitirá *.' . htmlspecialchars($domain) . ' por DNS-01 en el próximo ciclo del daemon.');
				}
			} else {
				$_SESSION['sencrypt_flash'] = array('ok', 'Wildcard desactivado para ' . htmlspecialchars($domain) . ' (el cert actual sigue vigente hasta su renovación normal).');
			}
		}
		if (!headers_sent()) { header('location: ./?module=sencrypt&ShowPanel=letsencrypt'); exit; }
	}

	# Toggle "Forzar HTTPS" por dominio (cliente): checkbox que auto-envía.
	static function ForceToggle($rowdomains) {
		$chk = ((int)$rowdomains['vh_forcessl_in'] !== 0) ? 'checked' : '';
		return '<form action="./?module=sencrypt&action=ForceSSL" method="post" style="display:inline">'
			. runtime_csfr::Token()
			. '<input type="hidden" name="inDomain" value="' . htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8') . '">'
			. '<input type="checkbox" name="inForce" value="1" ' . $chk . ' onchange="this.form.submit()"> '
			. ui_language::translate('Force HTTPS')
			. '</form>';
	}

	# Guarda el toggle SOLO en dominios del propio usuario (anti-IDOR) y regenera Apache.
	static function doForceSSL() {
		global $zdbh, $controller;
		runtime_csfr::Protect();
		$cu = ctrl_users::GetUserDetail();
		$domain = (string)$controller->GetControllerRequest('FORM', 'inDomain');
		$force  = $controller->GetControllerRequest('FORM', 'inForce') ? 1 : 0;
		$q = $zdbh->prepare("UPDATE x_vhosts SET vh_forcessl_in = :f WHERE vh_name_vc = :d AND vh_acc_fk = :u AND vh_deleted_ts IS NULL");
		$q->execute(array(':f' => $force, ':d' => $domain, ':u' => (int)$cu['userid']));
		$zdbh->exec("UPDATE x_settings SET so_value_tx = 'true' WHERE so_name_vc = 'apache_changed'");
		if (!headers_sent()) { header('location: ./?module=sencrypt&ShowPanel=letsencrypt'); exit; }
	}
	
	# Mensaje flash (PRG) de la reemisión: lo fija doForceReissue en $_SESSION y se muestra una vez.
	static function getReissueMessage() {
		if (empty($_SESSION['sencrypt_flash'])) return '';
		list($type, $msg) = $_SESSION['sencrypt_flash'];
		unset($_SESSION['sencrypt_flash']);
		return ui_sysmessage::shout($msg, $type === 'ok' ? 'zannounceok' : 'zannounce');
	}

	# Botón "Reemitir" (forzar reemisión del cert Let's Encrypt) por dominio/subdominio.
	static function ReissueButton($rowdomains) {
		return '<form action="./?module=sencrypt&action=ForceReissue" method="post" style="display:inline">'
			. runtime_csfr::Token()
			. '<input type="hidden" name="inDomain" value="' . htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8') . '">'
			. '<button class="button-loader btn btn-secondary" type="submit" name="inReissue" value="1">'
			. '<i class="bi bi-arrow-repeat me-1"></i>' . ui_language::translate('Reissue') . '</button>'
			. '</form>';
	}

	# ¿El dominio resuelve (por DNS) a UNA de las IP que este servidor sirve para él? Comprueba A
	# (IPv4) y AAAA (IPv6) contra server_ip, server_ip6 y las IP dedicadas del vhost, por inet_pton.
	# Evita "quemar" el límite de LE de 5 fallos de validación por identificador y hora.
	private static function domainResolvesToUs($domain, $v4dedic, $v6dedic) {
		$accept = array();
		foreach (array(ctrl_options::GetSystemOption('server_ip'), ctrl_options::GetSystemOption('server_ip6'), $v4dedic, $v6dedic) as $ip) {
			$ip = trim((string)$ip);
			if ($ip === '') continue;
			$p = @inet_pton($ip);
			if ($p !== false) { $accept[] = $p; }
		}
		if (empty($accept)) return false;
		$a = @dns_get_record($domain, DNS_A);
		if (!empty($a)) foreach ($a as $r) if (isset($r['ip']) && ($pp = @inet_pton($r['ip'])) !== false && in_array($pp, $accept, true)) return true;
		$aaaa = @dns_get_record($domain, DNS_AAAA);
		if (!empty($aaaa)) foreach ($aaaa as $r) if (isset($r['ipv6']) && ($pp = @inet_pton($r['ipv6'])) !== false && in_array($pp, $accept, true)) return true;
		return false;
	}

	# Fuerza la reemisión del certificado LE de un dominio/subdominio del PROPIO usuario (anti-IDOR),
	# con guardas para respetar los límites de Let's Encrypt:
	#   - cooldown de 48h entre reemisiones (el límite es 5 certs idénticos / 7 días -> ~3/semana);
	#   - el dominio debe resolver a una IP nuestra (evita 5 fallos de validación / identificador / hora).
	# No emite aquí: marca vh_le_reissue_ts y el hook OnDaemonDay (root) reemite en el próximo ciclo.
	static function doForceReissue() {
		global $zdbh, $controller;
		runtime_csfr::Protect();
		$cu     = ctrl_users::GetUserDetail();
		$domain = (string)$controller->GetControllerRequest('FORM', 'inDomain');

		$q = $zdbh->prepare("SELECT vh_id_pk, vh_name_vc, vh_custom_ip_vc, vh_custom_ip6_vc, vh_le_reissue_ts
		                     FROM x_vhosts WHERE vh_name_vc=:d AND vh_acc_fk=:u AND vh_deleted_ts IS NULL");
		$q->execute(array(':d' => $domain, ':u' => (int)$cu['userid']));
		$vh = $q->fetch(PDO::FETCH_ASSOC);
		if (!$vh) {
			$_SESSION['sencrypt_flash'] = array('err', ui_language::translate('Domain not valid.'));
		} else {
			$last = (int)($vh['vh_le_reissue_ts'] ?? 0);
			if ($last > 0 && (time() - $last) < 48 * 3600) {
				$h = ceil((48 * 3600 - (time() - $last)) / 3600);
				$_SESSION['sencrypt_flash'] = array('err',
					'Ya solicitaste una reemisión hace poco. Espera ~' . $h . ' h (Let\'s Encrypt limita a 5 certificados idénticos por 7 días).');
			} elseif (!self::domainResolvesToUs($domain, $vh['vh_custom_ip_vc'] ?? '', $vh['vh_custom_ip6_vc'] ?? '')) {
				$_SESSION['sencrypt_flash'] = array('err',
					'El dominio ' . htmlspecialchars($domain) . ' no resuelve por DNS a una IP de este servidor; corrige el DNS antes de reemitir (evita agotar el límite de fallos de validación de Let\'s Encrypt).');
			} else {
				$zdbh->prepare("UPDATE x_vhosts SET vh_le_reissue_ts=:t WHERE vh_id_pk=:id")
				     ->execute(array(':t' => time(), ':id' => (int)$vh['vh_id_pk']));
				$_SESSION['sencrypt_flash'] = array('ok',
					'Reemisión programada para ' . htmlspecialchars($domain) . '. Se emitirá en el próximo ciclo del daemon.');
			}
		}
		if (!headers_sent()) { header('location: ./?module=sencrypt&ShowPanel=letsencrypt'); exit; }
	}

	static function Show_list_of_active_domain_ssl() {
		global $zdbh, $controller;
	    $currentuser = ctrl_users::GetUserDetail();
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		
		# Show Client SSL certs
		$sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() > 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();
            while ($rowdomains = $sql->fetch()) {
				# Check if folder ssl exists
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/") ) {
					fs_director::CreateDirectory( ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" );
				}
				
				# If Third Party Vhost cert	
				if ( is_file(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/cert.pem" ) ) {
	
					$button = '<form action="./?module=sencrypt&ShowPanel=third-party&action=TPDelete" method="post">
						<input type="hidden" name="inName" value="'. $rowdomains['vh_name_vc'] .'">
						<button class="btn btn-warning" type="submit" id="button" name="inDelete_'. $currentuser["username"].'" id="inDelete_'. $currentuser["username"].'" value="inDelete_' . $currentuser["username"] . '"><i class="bi bi-trash me-1"></i>' . ui_language::translate("Delete") . '</button></td>
						 '.runtime_csfr::Token().'
					</form>';
					
					$Downloadbutton = '<form action="./?module=sencrypt&ShowPanel=third-party&action=Download" method="post">'
						. runtime_csfr::Token() .
						'<input type="hidden" name="inName" value="'. htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8') .'">
							<button class="btn btn-primary1" type="submit" id="button" name="inDownload_'. $currentuser["username"].'" id="inDownload_'. $currentuser["username"].'" value="inDownload_'. $currentuser["username"].'"><i class="bi bi-download me-1"></i>' . ui_language::translate("Download") . '</button></td>
					</form>';
					
					$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/cert.pem"));
					$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
					$now = time();
					$your_date = strtotime("$validTo");
					$datediff = $your_date - $now;
					$day = floor($datediff / (60 * 60 * 24));
					$sslvendor = "Third-Party";
					
					$reNewDay = $day - 30;
					
					if($day <= "-1700") {
						$days = ui_language::translate("Not initialized yet"); } else {
						$days = ui_language::translate("Expiry in") . ' ' . $day . ' ' . ui_language::translate("days") . ".";
					}
							
					$res[] = array('Domain_AC' => $rowdomains['vh_name_vc'], 'Button_AC' => $button, 'Vendor_AC' => $sslvendor, 'Days_AC' =>  $days, 'Download_AC' => $Downloadbutton, 'Revoke_AC' => NULL, 'Force_AC' => self::ForceToggle($rowdomains) , 'Reissue_AC' => NULL, 'Wildcard_AC' => NULL );
					
				# If Letsencrypt cert	
				} elseif ( is_file(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/cert.pem" ) ) {
					
					$button = '<form action="./?module=sencrypt&ShowPanel=letsencrypt&action=Delete" method="post">'
					. runtime_csfr::Token() .
					'<input type="hidden" name="inDomain" value="'. htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8').'">
						<button class="button-loader btn btn-warning" type="submit" id="button" name="inDeleteSSL" id="inDeleteSSL" value="inDeleteSSL"><i class="bi bi-trash me-1"></i>' . ui_language::translate("Delete") . '</button>
					</form>';
					
					# Revoke button just incase its needed
					$RevokeButton = '<form action="./?module=sencrypt&action=RevokeSSL" method="post">'
					. runtime_csfr::Token() .
					'<input type="hidden" name="inDomain" value="'. htmlspecialchars($rowdomains['vh_name_vc'], ENT_QUOTES, 'UTF-8').'">
						<button class="button-loader btn btn btn-danger" type="submit" id="button" name="inRevokeSSL" id="inRevokeSSL" value="inRevokeSSL"><i class="bi bi-slash-circle me-1"></i>' . ui_language::translate("Revoke") . '</button>
					</form>';
					
					$certinfo = openssl_x509_parse(file_get_contents(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/cert.pem"));
					$validTo = date('Y-m-d', $certinfo["validTo_time_t"]);
					$now = time();
					$your_date = strtotime("$validTo");
					$datediff = $your_date - $now;
					$day = floor($datediff / (60 * 60 * 24));
					$sslvendor = "Lets Encrypt";

					$reNewDay = $day - 30;

					if($day <= "-1700") {
						$days = ui_language::translate("Not initialized yet"); } else {
						$days = ui_language::translate("Expiry in") . ' ' . $day . ' ' . ui_language::translate("days") . ' - ' . ui_language::translate("Auto-renewal in") . ' ' . $reNewDay . ' ' . ui_language::translate("days") . '.';
					}
					
					$res[] = array('Domain_AC' => $rowdomains['vh_name_vc'], 'Button_AC' => $button, 'Vendor_AC' => $sslvendor, 'Days_AC' =>  $days, 'Download_AC' => NULL, 'Revoke_AC' => $RevokeButton, 'Force_AC' => self::ForceToggle($rowdomains), 'Reissue_AC' => self::ReissueButton($rowdomains), 'Wildcard_AC' => self::WildcardButton($rowdomains));
					
				}
			}
			if (!$res)
			{
				$res[] = array('Domain_AC' => ui_language::translate("No Active Domain Certificates"), 'Button_AC' => NULL, 'Vendor_AC' => NULL, 'Days_AC' =>  NULL, 'Download_AC' => NULL, 'Revoke_AC' => NULL, 'Force_AC' => NULL, 'Reissue_AC' => NULL, 'Wildcard_AC' => NULL);
			}

		} else {		
								
			$res[] = array('Domain_AC' => ui_language::translate("No Active Domain Certificates"), 'Button_AC' => NULL, 'Vendor_AC' => NULL, 'Days_AC' =>  NULL, 'Download_AC' => NULL, 'Revoke_AC' => NULL, 'Force_AC' => NULL, 'Reissue_AC' => NULL, 'Wildcard_AC' => NULL);
			
		}
		
		return $res;
	}

# Module & Dispaly stuff - END

# Third_Party code below - START

	static function ExecuteDownload($domain, $username) {
		set_time_limit(0);
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();

		// Reject any non-hostname characters (blocks ../ traversal)
		if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $domain) || $domain === '') {
			return false;
		}
		// Ownership: domain must belong to this user
		$chk = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:d AND vh_acc_fk=:u AND vh_deleted_ts IS NULL");
		$chk->execute([':d' => $domain, ':u' => $currentuser['userid']]);
		if ($chk->fetchColumn() == 0) return false;

		// realpath containment
		$sslBase = realpath(ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/third_party');
		$sslDir  = ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/third_party/' . $domain . '/';
		$realSSL = realpath(rtrim($sslDir, '/'));
		if ($sslBase === false || $realSSL === false || strpos($realSSL . '/', $sslBase . '/') !== 0) {
			return false;
		}

		$rootdir = str_replace('.', '_', $domain);

		$temp_dir = ctrl_options::GetSystemOption('bulwark_root') . "etc/tmp/";
		$homedir = ctrl_options::GetSystemOption('hosted_dir') . $username;
    	$backupname = $rootdir;
		// Crear zip con ZipArchive (sin exec ni shell)
		$sslDir = $homedir . '/ssl/sencrypt/third_party/' . $domain . '/';
		$zipPath = $temp_dir . $backupname . '.zip';
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
			foreach (glob($sslDir . '*') as $file) {
				if (is_file($file)) {
					$zip->addFile($file, basename($file));
				}
			}
			$zip->close();
		}
        @chmod($zipPath, 0600);
		$filename = $backupname . ".zip";
		$filepath = $temp_dir;
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Cache-Control: public");
		header("Content-Description: File Transfer");
		header("Content-type: application/octet-stream");
		header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
		header("Content-Transfer-Encoding: binary");
		header("Content-Length: ".filesize($filepath. $filename));
		ob_end_flush();
		readfile($filepath. $filename);
		unlink($temp_dir . $backupname . ".zip");
		return true;
	}
	
	static function doDownload() {
        runtime_csfr::Protect();
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
        if (self::ExecuteDownload($formvars['inName'], $currentuser["username"])) {
            return true;
		}
    }

	static function doMakeCSR() {
		global $controller;
		runtime_csfr::Protect();
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		if (empty($formvars['inDomain']) || empty($formvars['inName']) || empty($formvars['inAddress']) || empty($formvars['inCity']) || empty($formvars['inCountry']) || empty($formvars['inCompany'])) { 
			self::$empty = true;
			return false;
		}
		if (self::ExecuteCSR($formvars['inDomain'], $formvars['inName'], $formvars['inAddress'], $formvars['inCity'], $formvars['inCountry'], $formvars['inCompany'], $formvars['inPassword'])) {
			return true;
		}
	}
		
	static function ExecuteCSR($domain, $name, $address, $city, $country, $company, $password) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		$config = array('digest_alg' => 'sha256', 'private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA,  'encrypt_key' => true);
		$csrconfig = array('digest_alg' => 'sha256');
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/") ) {
			fs_director::CreateDirectory(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/" );
		}
			
		$dn = array(
					"countryName" => "$country",
					"stateOrProvinceName" => "$name",
					"localityName" => "$city",
					"organizationName" => "$company",
					"commonName" => "$domain",
					"emailAddress" => "$address"
		);
			
		$privkey = openssl_pkey_new($config);
		$csr = openssl_csr_new($dn, $privkey, $csrconfig);
			
		openssl_csr_export($csr, $csrout);
		openssl_pkey_export($privkey, $pkeyout, $password);
			
		openssl_pkey_export_to_file($privkey, ctrl_options::GetSystemOption('hosted_dir'). $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			
			$email = $address;
			$emailsubject = "Certificate Signing Request";
			$emailbody = "Hi $currentuser[username]\n\n
			---------------------------------CSR START-------------------------------
			\n\n\n
			$csrout
			\n\n\n
			---------------------------------CSR END-------------------------------";
			
			# PHP Mailer option
			$phpmailer = new sys_email();
			//$phpmailer->From = "info@sentora.org";
			$phpmailer->Subject = $emailsubject;
			$phpmailer->Body = $emailbody;
			$phpmailer->addAttachment(ctrl_options::GetSystemOption('hosted_dir'). $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			$phpmailer->addAddress($email);
			$phpmailer->SendEmail();

			unlink(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/" . $domain . ".key");
			rmdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/key/");
			self::$keyadd = true;
			return true;
			
	}

	static function doTPDelete() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');

		$sub_module = "third_party";
		
        if (self::ExecuteTPDelete($formvars['inName'], $currentuser["username"], $sub_module)) {
            return true;
		}
    }

	static function ExecuteTPDelete($domain, $username, $sub_module) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();

		// Whitelist sub_module
		if (!in_array($sub_module, ['letsencrypt', 'third_party'], true)) {
			return false;
		}
		// Reject any non-hostname characters (blocks ../ traversal)
		if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $domain) || $domain === '') {
			return false;
		}
		// Ownership: domain must belong to this user, or be the panel domain for admins only
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		if ($domain === $panelDomain) {
			if ((int)$currentuser['usergroupid'] !== ctrl_groups::GROUP_ADMIN) return false;
		} else {
			$chk = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:d AND vh_acc_fk=:u AND vh_deleted_ts IS NULL");
			$chk->execute([':d' => $domain, ':u' => $currentuser['userid']]);
			if ($chk->fetchColumn() == 0) return false;
		}
		// realpath containment
		$baseDir = realpath(ctrl_options::GetSystemOption('hosted_dir') . $username . '/ssl/sencrypt/' . $sub_module);
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/" . $sub_module . "/" . $domain;
		$realDir = realpath($dir);
		if ($baseDir === false || $realDir === false || strpos($realDir . '/', $baseDir . '/') !== 0) {
			return false;
		}
		$objects = scandir($dir);
		
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				unlink($dir."/" . $object);
			}
		}
			
		rmdir($dir);

		# Bulwark domain CERTS
		if($domain == ctrl_options::GetSystemOption('bulwark_domain')) {
			
			# For Letsencrypt or third-party NON Self signed (lecrypt)
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();

			# If Letsencrypt or purchased SSL
			if ( $sub_module == "letsencrypt" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "third_party" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "self_signed" ) {
				# self signed - DO NOthing
			}
			
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
		# NEW CODE
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				# For older Bulwark support
				$new = '';
				$name = 'global_zpcustom';
				
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':name', $name);
				$sql->execute();
	
			} else {
				# For Bulwark 2.0
				$new = NULL;
				$name = 'panel_ssl_tx';
					
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':name', $name);
				$sql->execute();		
			
			}
		
		# For Self signed
		# NEW CODE - this should be for self signed...

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();

			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
			# Update Data
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :name");
			$sql->bindParam(':data', $line);
			$sql->bindParam(':new', $new);
			$sql->bindParam(':name', $name);
			$sql->execute();
			
			# Update Port
			//$portname = "bulwark_port";
			//$port = "80";
			
			//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
			//$updatesql->bindParam(':value', $port);
			//$updatesql->bindParam(':name', $portname);
			//$updatesql->execute();
			
		} else {
			
			# USER Domain Letsencrypt and Third-party CERT (lecrypt)
			
			$port 			= NULL;
			$portforward	= NULL;
						
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			
			# If Letsencrypt or purchased SSL
			if ( $sub_module == "letsencrypt" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "third_party" ) {
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				
			} elseif ( $sub_module == "self_signed" ) {
				# self signed - DO NOthing
			}
			
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
				
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {	
				# For older Bulwark support
				$new = '';
				
				$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':domain', $domain);
				$sql->bindParam(':port', $new);
				//$sql->bindParam(':portforward', $new);
				$sql->execute();
				
			} else {
				# For Bulwark 2.0
				$new = NULL;
				
				$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port, vh_custom_port_in=:customport WHERE vh_name_vc = :domain");
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port, vh_custom_port_in=:customport, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':new', $new);
				$sql->bindParam(':port', $port);
				$sql->bindParam(':customport', $new);
				//$sql->bindParam(':portforward', $new);
				$sql->bindParam(':domain', $domain);
				$sql->execute();
													
			}
			
			# Self Signed 

				$ssline = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
				$ssline .= fs_filehandler::NewLine();
				$ssline .= 'SSLEngine On' . fs_filehandler::NewLine();
				$ssline .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
				$ssline .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$ssline .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();				
				$ssline .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$ssline .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/" . $sub_module . "/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$ssline .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();
	
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {	
					# For older Bulwark support
					$new = '';
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $ssline);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $new);
					//$sql->bindParam(':portforward', $new);
					$sql->execute();
					
				} else {
					# For Bulwark 2.0
					$new = NULL;
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $ssline);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $new);
					$sql->execute();
				}
# Self sign END
	
		}

	  	self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;

	}

	static function doUploadSSL() {
        runtime_csfr::Protect();
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		$domain = $formvars["inDomain"];

		if (empty($_FILES["inkey"]["name"]) || empty($_FILES["inWCA"]["name"])) {
			self::$empty = true;
			return false;
		}

		if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $domain) || $domain === '') {
			self::$error = true;
			return false;
		}
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		if ($domain !== $panelDomain) {
			$chk = $zdbh->prepare("SELECT COUNT(*) FROM x_vhosts WHERE vh_name_vc=:d AND vh_acc_fk=:u AND vh_deleted_ts IS NULL");
			$chk->execute([':d' => $domain, ':u' => $currentuser['userid']]);
			if ((int)$chk->fetchColumn() === 0) {
				self::$error = true;
				return false;
			}
		} else {
			if ((int)$currentuser['usergroupid'] !== ctrl_groups::GROUP_ADMIN) {
				self::$error = true;
				return false;
			}
		}

		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/", 0777);
		}
			
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/") ) {
				mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/", 0777);
			} else {
				self::$error = true;
				return false;
			}
			
			$target_dir = ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/";
			
			$uploadkey = $target_dir . "private.pem";
			$uploadwcrt = $target_dir . "cert.pem";
			$uploadicrt = $target_dir . "intermediate.crt";
			
			move_uploaded_file($_FILES["inkey"]["tmp_name"], $uploadkey);
			move_uploaded_file($_FILES["inWCA"]["tmp_name"], $uploadwcrt);
			move_uploaded_file($_FILES["inICA"]["tmp_name"], $uploadicrt);
			
			if($domain == ctrl_options::GetSystemOption('bulwark_domain')) {
			
				$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
				$line  .= fs_filehandler::NewLine();
				$line .= 'SSLEngine On' . fs_filehandler::NewLine();
				$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();		
				$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();				
				$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();

				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					# For older Bulwark support
					$sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_name_vc  = :name");
					$sql->bindParam(':name', $name);
					$sql->execute();
				
					while ($row = $sql->fetch()) { $olddata = $row['so_value_tx']; }
						$data = $olddata. $line;
						$name = 'global_zpcustom';
						
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $data);
						$updatesql->bindParam(':name', $name);
						$updatesql->execute();
							
						$portname = "bulwark_port";
						$port = "443";
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $port);
						$updatesql->bindParam(':name', $portname);
						$updatesql->execute();

				} else {
					# For Bulwark 2.0
						$name = 'panel_ssl_tx';
						
						# update panel data
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $line);
						$updatesql->bindParam(':name', $name);
						$updatesql->execute();
					
						# Update panel port					
						$portname = "bulwark_port";
						$port = "443";
						$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
						$updatesql->bindParam(':value', $port);
						$updatesql->bindParam(':name', $portname);
						$updatesql->execute();
						
				}

			} else {
				
				$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
				$line .= fs_filehandler::NewLine();
                $line .= 'SSLEngine On' . fs_filehandler::NewLine();
				$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
				$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
				$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
				$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
				$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
				$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/intermediate.crt". fs_filehandler::NewLine();
				$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();
				
				$port = "443";
				
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					# For older Bulwark support
					$portforward 	= "1";
					
					$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
					$sql->bindParam(':domain', $domain);
					$sql->execute();
					
					while ($row = $sql->fetch()) { 
						$olddata = $row['vh_custom_tx']; 
					}	
						
						$data = $olddata. $line;
						
						$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
						//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
						$sql->bindParam(':data', $data);
						$sql->bindParam(':domain', $domain);
						$sql->bindParam(':port', $port);
						//$sql->bindParam(':portforward', $portforward);
						$sql->execute();
					
				} else {
					# For Bulwark 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->execute();
						
				}
			}
			
			self::SetWriteApacheConfigTrue();
			self::$okthirdparty = true;
			return true;
	}

	static function doMakenew() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		if (empty($formvars['inDomain']) || empty($formvars['inName']) || empty($formvars['inAddress']) || empty($formvars['inCity']) || empty($formvars['inCountry']) || empty($formvars['inCompany'])) { 
			self::$empty = true;
			return false;
		}
        if (self::ExecuteMakeTPssl($formvars['inDomain'], $formvars['inName'], $formvars['inAddress'], $formvars['inCity'], $formvars['inCountry'], $formvars['inCompany']))
	        return true;
	}
		
	static function ExecuteMakeTPssl($domain, $name, $address, $city, $country, $company) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		$rootdir = str_replace('.', '_', $domain);
		
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party", 0777);
		}

		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/") ) {
			mkdir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/", 0777);	
		} else {
			
			self::$error = true;
			return false;
		}
		
		# GET user info
		$dn = array(
					"countryName" => "$country",
					"stateOrProvinceName" => "$name",
					"localityName" => "$city",
					"organizationName" => "$company",
					"commonName" => "$domain",
					"emailAddress" => "$address",
					"subjectAltName" => "DNS: $domain, DNS: www. $domain"
		); 		

		# Make Key
		$privkey = openssl_pkey_new();
			
		# Generate a certificate signing request
		$csr = openssl_csr_new($dn, $privkey);
			
		$config = array("digest_alg" => "sha256", "x509_extensions" => "v3_req");
			
		$sscert = openssl_csr_sign($csr, null, $privkey, 365, $config);
			
		openssl_x509_export_to_file($sscert, ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem");
		openssl_pkey_export_to_file($privkey, ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem");	
		
		if ( $domain == ctrl_options::GetSystemOption('bulwark_domain') ) {
					
			$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();					

			$portname = "bulwark_port";
			$port = "443";
				
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				# For older Bulwark support
				$name = 'global_zpcustom';
				$sql = $zdbh->prepare("SELECT * FROM x_settings WHERE so_name_vc  = :name");
				$sql->bindParam(':name', $name);
				$sql->execute();
						
				while ($row = $sql->fetch()) { 
					$olddata = $row['so_value_tx']; 
					
				}
					$data = $olddata. $line;
								
					# Update data
					$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					$updatesql->bindParam(':value', $data);
					$updatesql->bindParam(':name', $name);
					$updatesql->execute();
								
					# Update port
					//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					//$updatesql->bindParam(':value', $port);
					//$updatesql->bindParam(':name', $portname);
					//$updatesql->execute();
								
			} else {
				# For Bulwark 2.0
					$name = 'panel_ssl_tx';
					
					# Update data
					$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					$updatesql->bindParam(':value', $line);
					$updatesql->bindParam(':name', $name);
					$updatesql->execute();
								
					# Update port
					//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
					//$updatesql->bindParam(':value', $port);
					//$updatesql->bindParam(':name', $portname);
					//$updatesql->execute();
						
			}

		} else {
					
			$line = "# Made from Sencrypt - third_party - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();	
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/third_party/" . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - third_party - end" . fs_filehandler::NewLine();
			
			$port = "443";
	
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				# For older Bulwark support
				$portforward 	= "1";								

				$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
				$sql->bindParam(':domain', $domain);
				$sql->execute();
							
				while ($row = $sql->fetch()) { 
					$olddata = $row['vh_custom_tx']; 
				
				}
					$data = $olddata. $line;
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':portforward', $portforward);
					$sql->execute();
	
			} else {
					# For Bulwark 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':port', $port);
					$sql->execute();
		
			}	
		}

			
		self::SetWriteApacheConfigTrue();
		self::$okselfsigned = true;	
		return true;	
				
	}

	static function ListDomains($uid) {
        global $zdbh;
		global $controller;
        $currentuser = ctrl_users::GetUserDetail($uid);
        $sql = "SELECT * FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
        $numrows = $zdbh->prepare($sql);
        $numrows->bindParam(':userid', $currentuser['userid']);
        $numrows->execute();
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare($sql);
            $sql->bindParam(':userid', $currentuser['userid']);
            $res = array();
            $sql->execute();

			# Panel values
			$panel_Domain = ctrl_options::GetSystemOption('bulwark_domain');
			
			# Check if ssl exists else where
			if ( !is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $panel_Domain . "/") ) {
			
				# check if cert exist or not
				if ( is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $panel_Domain . "/") ) {
					# Do nothing

				} else {
					
					$name = $panel_Domain;
					$res[] = array('domain' => "$name");
				}
				
			} else {
				# Do nothing
				
			}
	
            while ($rowdomains = $sql->fetch()) {
				
				#check if cert exist or not
				if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/third_party/" . $rowdomains['vh_name_vc'] . "/") ) {

					# Check if ssl exists else where
					if ( is_dir(ctrl_options::GetSystemOption('hosted_dir') . $currentuser["username"] . "/ssl/sencrypt/letsencrypt/" . $rowdomains['vh_name_vc'] . "/") ) {
						# Do nothing

					} else {
						$res[] = array('domain' => $rowdomains['vh_name_vc']);
				
					}				
				} else {
					# Do nothing

				}				
            }
            return $res;
			
        } else {
            return false;
        }
	}
		
	static function getDomainList() {
			$currentuser = ctrl_users::GetUserDetail();
			return self::ListDomains($currentuser['userid']);
	}
	
	static function ListSSL($uname) {
		global $controller;
		$retval = null;
		if (!is_dir(ctrl_options::GetSystemOption('hosted_dir') . $uname . "/ssl/sencrypt/third_party/") ) {
			mkdir( ctrl_options::GetSystemOption('hosted_dir'). $uname . "/ssl/sencrypt/third_party/", 0777);
		}
		
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $uname . "/ssl/sencrypt/third_party/";
		if(substr($dir, -1) != "/") $dir .= "/";
			$d = @dir($dir);
			while(false !== ($entry = $d->read())) {
			$entry1 = str_replace('_', '.', $entry);
			if($entry[0] == ".") continue;
				$retval[] = array("name" => "$entry1");
		}
		
		$d->close();
		return $retval;
	}

	static function getSSLList() {
		$currentuser = ctrl_users::GetUserDetail();
		return self::ListSSL($currentuser['username']);
	}
		
	static function getisShowCSR() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "ShowCSR");
	}
	
	static function getisShowSelf() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "ShowSelf");
	}
	
	static function getisBought() {
        global $controller;
        $urlvars = $controller->GetAllControllerRequests('URL');
        return (isset($urlvars['show'])) && ($urlvars['show'] == "Bought");
	}

	static function doselect() {
        global $controller;
        runtime_csfr::Protect();
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');
		
            if (isset($formvars['inSSLself'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=ShowSelf');
                exit;
            }
			if (isset($formvars['inSSLbought'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=Bought');
                exit;
            }
			if (isset($formvars['inSSLCSR'])) {
                header("location: ./?module=" . $controller->GetCurrentModule() . '&ShowPanel=third-party' . '&show=ShowCSR');
                exit;
            }
        return true;
	}

	static function getListCountry() {
			$res = '<option value="AF">Afghanistan</option> <option value="AX">Åland Islands</option> <option value="AL">Albania</option> <option value="DZ">Algeria</option> <option value="AS">American Samoa</option> <option value="AD">Andorra</option> <option value="AO">Angola</option> <option value="AI">Anguilla</option> <option value="AQ">Antarctica</option> <option value="AG">Antigua and Barbuda</option> <option value="AR">Argentina</option> <option value="AM">Armenia</option> <option value="AW">Aruba</option> <option value="AU">Australia</option> <option value="AT">Austria</option> <option value="AZ">Azerbaijan</option> <option value="BS">Bahamas</option> <option value="BH">Bahrain</option> <option value="BD">Bangladesh</option> <option value="BB">Barbados</option> <option value="BY">Belarus</option> <option value="BE">Belgium</option> <option value="BZ">Belize</option> <option value="BJ">Benin</option> <option value="BM">Bermuda</option> <option value="BT">Bhutan</option> <option value="BO">Bolivia</option> <option value="BA">Bosnia and Herzegovina</option> <option value="BW">Botswana</option> <option value="BV">Bouvet Island</option> <option value="BR">Brazil</option> <option value="IO">British Indian Ocean Territory</option> <option value="BN">Brunei Darussalam</option> <option value="BG">Bulgaria</option> <option value="BF">Burkina Faso</option> <option value="BI">Burundi</option> <option value="KH">Cambodia</option> <option value="CM">Cameroon</option> <option value="CA">Canada</option> <option value="CV">Cape Verde</option> <option value="KY">Cayman Islands</option> <option value="CF">Central African Republic</option> <option value="TD">Chad</option> <option value="CL">Chile</option> <option value="CN">China</option> <option value="CX">Christmas Island</option> <option value="CC">Cocos (Keeling) Islands</option> <option value="CO">Colombia</option> <option value="KM">Comoros</option> <option value="CG">Congo</option> <option value="CD">Congo, The Democratic Republic of The</option> <option value="CK">Cook Islands</option> <option value="CR">Costa Rica</option> <option value="CI">Cote D´ivoire</option> <option value="HR">Croatia</option> <option value="CU">Cuba</option> <option value="CY">Cyprus</option> <option value="CZ">Czech Republic</option> <option value="DK">Denmark</option> <option value="DJ">Djibouti</option> <option value="DM">Dominica</option> <option value="DO">Dominican Republic</option> <option value="EC">Ecuador</option> <option value="EG">Egypt</option> <option value="SV">El Salvador</option> <option value="GQ">Equatorial Guinea</option> <option value="ER">Eritrea</option> <option value="EE">Estonia</option> <option value="ET">Ethiopia</option> <option value="FK">Falkland Islands (Malvinas)</option> <option value="FO">Faroe Islands</option> <option value="FJ">Fiji</option> <option value="FI">Finland</option> <option value="FR">France</option> <option value="GF">French Guiana</option> <option value="PF">French Polynesia</option> <option value="TF">French Southern Territories</option> <option value="GA">Gabon</option> <option value="GM">Gambia</option> <option value="GE">Georgia</option> <option value="DE">Germany</option> <option value="GH">Ghana</option> <option value="GI">Gibraltar</option> <option value="GR">Greece</option> <option value="GL">Greenland</option> <option value="GD">Grenada</option> <option value="GP">Guadeloupe</option> <option value="GU">Guam</option> <option value="GT">Guatemala</option> <option value="GG">Guernsey</option> <option value="GN">Guinea</option> <option value="GW">Guinea-bissau</option> <option value="GY">Guyana</option> <option value="HT">Haiti</option> <option value="HM">Heard Island and Mcdonald Islands</option> <option value="VA">Holy See (Vatican City State)</option> <option value="HN">Honduras</option> <option value="HK">Hong Kong</option> <option value="HU">Hungary</option> <option value="IS">Iceland</option> <option value="IN">India</option> <option value="ID">Indonesia</option> <option value="IR">Iran, Islamic Republic of</option> <option value="IQ">Iraq</option> <option value="IE">Ireland</option> <option value="IM">Isle of Man</option> <option value="IL">Israel</option> <option value="IT">Italy</option> <option value="JM">Jamaica</option> <option value="JP">Japan</option> <option value="JE">Jersey</option> <option value="JO">Jordan</option> <option value="KZ">Kazakhstan</option> <option value="KE">Kenya</option> <option value="KI">Kiribati</option> <option value="KP">Korea, Democratic People´s Republic of</option> <option value="KR">Korea, Republic of</option> <option value="KW">Kuwait</option> <option value="KG">Kyrgyzstan</option> <option value="LA">Lao People´s Democratic Republic</option> <option value="LV">Latvia</option> <option value="LB">Lebanon</option> <option value="LS">Lesotho</option> <option value="LR">Liberia</option> <option value="LY">Libyan Arab Jamahiriya</option> <option value="LI">Liechtenstein</option> <option value="LT">Lithuania</option> <option value="LU">Luxembourg</option> <option value="MO">Macao</option> <option value="MK">Macedonia, The Former Yugoslav Republic of</option> <option value="MG">Madagascar</option> <option value="MW">Malawi</option> <option value="MY">Malaysia</option> <option value="MV">Maldives</option> <option value="ML">Mali</option> <option value="MT">Malta</option> <option value="MH">Marshall Islands</option> <option value="MQ">Martinique</option> <option value="MR">Mauritania</option> <option value="MU">Mauritius</option> <option value="YT">Mayotte</option> <option value="MX">Mexico</option> <option value="FM">Micronesia, Federated States of</option> <option value="MD">Moldova, Republic of</option> <option value="MC">Monaco</option> <option value="MN">Mongolia</option> <option value="ME">Montenegro</option> <option value="MS">Montserrat</option> <option value="MA">Morocco</option> <option value="MZ">Mozambique</option> <option value="MM">Myanmar</option> <option value="NA">Namibia</option> <option value="NR">Nauru</option> <option value="NP">Nepal</option> <option value="NL">Netherlands</option> <option value="AN">Netherlands Antilles</option> <option value="NC">New Caledonia</option> <option value="NZ">New Zealand</option> <option value="NI">Nicaragua</option> <option value="NE">Niger</option> <option value="NG">Nigeria</option> <option value="NU">Niue</option> <option value="NF">Norfolk Island</option> <option value="MP">Northern Mariana Islands</option> <option value="NO">Norway</option> <option value="OM">Oman</option> <option value="PK">Pakistan</option> <option value="PW">Palau</option> <option value="PS">Palestinian Territory, Occupied</option> <option value="PA">Panama</option> <option value="PG">Papua New Guinea</option> <option value="PY">Paraguay</option> <option value="PE">Peru</option> <option value="PH">Philippines</option> <option value="PN">Pitcairn</option> <option value="PL">Poland</option> <option value="PT">Portugal</option> <option value="PR">Puerto Rico</option> <option value="QA">Qatar</option> <option value="RE">Reunion</option> <option value="RO">Romania</option> <option value="RU">Russian Federation</option> <option value="RW">Rwanda</option> <option value="SH">Saint Helena</option> <option value="KN">Saint Kitts and Nevis</option> <option value="LC">Saint Lucia</option> <option value="PM">Saint Pierre and Miquelon</option> <option value="VC">Saint Vincent and The Grenadines</option> <option value="WS">Samoa</option> <option value="SM">San Marino</option> <option value="ST">Sao Tome and Principe</option> <option value="SA">Saudi Arabia</option> <option value="SN">Senegal</option> <option value="RS">Serbia</option> <option value="SC">Seychelles</option> <option value="SL">Sierra Leone</option> <option value="SG">Singapore</option> <option value="SK">Slovakia</option> <option value="SI">Slovenia</option> <option value="SB">Solomon Islands</option> <option value="SO">Somalia</option> <option value="ZA">South Africa</option> <option value="GS">South Georgia and The South Sandwich Islands</option> <option value="ES">Spain</option> <option value="LK">Sri Lanka</option> <option value="SD">Sudan</option> <option value="SR">Suriname</option> <option value="SJ">Svalbard and Jan Mayen</option> <option value="SZ">Swaziland</option> <option value="SE">Sweden</option> <option value="CH">Switzerland</option> <option value="SY">Syrian Arab Republic</option> <option value="TW">Taiwan, Province of China</option> <option value="TJ">Tajikistan</option> <option value="TZ">Tanzania, United Republic of</option> <option value="TH">Thailand</option> <option value="TL">Timor-leste</option> <option value="TG">Togo</option> <option value="TK">Tokelau</option> <option value="TO">Tonga</option> <option value="TT">Trinidad and Tobago</option> <option value="TN">Tunisia</option> <option value="TR">Turkey</option> <option value="TM">Turkmenistan</option> <option value="TC">Turks and Caicos Islands</option> <option value="TV">Tuvalu</option> <option value="UG">Uganda</option> <option value="UA">Ukraine</option> <option value="AE">United Arab Emirates</option> <option value="GB">United Kingdom</option> <option value="US">United States</option> <option value="UM">United States Minor Outlying Islands</option> <option value="UY">Uruguay</option> <option value="UZ">Uzbekistan</option> <option value="VU">Vanuatu</option> <option value="VE">Venezuela</option> <option value="VN">Viet Nam</option> <option value="VG">Virgin Islands, British</option> <option value="VI">Virgin Islands, U.S.</option> <option value="WF">Wallis and Futuna</option> <option value="EH">Western Sahara</option> <option value="YE">Yemen</option> <option value="ZM">Zambia</option> <option value="ZW">Zimbabwe</option>';
			return $res;
	}

# Third_Party code - END

# LETS Encrypt code - START
# Client
	static function doMakeSSL() {
        runtime_csfr::Protect();
		global $controller;

		$sub_module = "letsencrypt";
		
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');

		if (self::ExecuteMakeSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}
	
# Panel		
	static function doMakePanelSSL() {
        runtime_csfr::Protect();
		global $controller;

		$sub_module = "letsencrypt";
		
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		if (self::ExecuteMakePanelSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}

# make client	
	static function ExecuteMakeSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;

		// Security fix (June 2026): removed dead `$zsudo = ctrl_options::GetOption('zsudo');`.
		// zsudo (setuid wrapper) is gone; privilege escalation now goes through
		// sudo/doas via dryden/sys/privilege.class.php. This function never used
		// the value anyway, so the assignment was just confusing dead code.
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $domain;
		# NEW CODE - tg - get hosted folder from DB (vh_directory_vc) so that challenge is in propper location
		$sql = $zdbh->prepare("SELECT vh_directory_vc FROM x_vhosts WHERE vh_name_vc = :domain");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
		$domain_folder=$sql->fetchColumn();
		//$domain_folder = str_replace(".","_", $domain);
		$_vhp = ctrl_options::GetVhostPaths($username, $domain_folder);
		$domainRoot = $_vhp['public_html'];

		require("modules/sencrypt/code/Lescript.php");
		date_default_timezone_set("UTC");
		
		$user_ip = ctrl_options::GetSystemOption('server_ip');

		# Check DNS before continuing
		if (!self::checkDNSIsLive($domain, $user_ip)) {
			self::$dnsInvalid = true;
			return false;
		}

		# Make Let´s encrypt SSL
		$logger = new Logger();

		try {

			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger);

			# uses client's email used during registration
			$le->contact = array('mailto:'. $currentuser['email']); // optional
			
			# Init. Account and update account email to keep current.
			$le->initAccount();
			$le->postUpdateRegEmail();
			
			# Start signing here
			
			# NEW CODE - start - tg
			# Check if domain has a shared hostdata dir
			$sql = "SELECT COUNT(*) FROM x_vhosts WHERE vh_acc_fk = :userid AND vh_directory_vc = :dom_directory";
				$query = $zdbh->prepare($sql);
				$query->bindParam(':userid', $currentuser['userid']);
				$query->bindParam(':dom_directory', $domain_folder);
				$query->execute();
			$count = $query->fetchColumn();
			# NEW CODE - end - tg
	
			# Check if domain is a subdomain
			$sql = "SELECT vh_type_in FROM x_vhosts WHERE vh_acc_fk=:userid AND vh_name_vc=:domain AND vh_enabled_in=1 AND vh_deleted_ts IS NULL ORDER BY vh_name_vc ASC";
				$query = $zdbh->prepare($sql);
				$query->bindParam(':userid', $currentuser['userid']);
				$query->bindParam(':domain', $domain);
				$query->execute();
		
			while ($row = $query->fetch()) {

				if (($row['vh_type_in'] == 2 ) || ($count != 1 )) {
					# Create domain without www. because its a subdomain
					$le->signDomains(array($domain));

				} else {

					# Create a SSL for domain and with www. because its a root domain
					$le->signDomains(array($domain, 'www.'. $domain));
				}
			}			
		}
	
		catch (\Exception $e) {
			$errorCatahed = $e->getMessage();
			$logger->error($e->getTraceAsString());
			$logger->error($e->getMessage());
			# Throw error and log to file
			error_log( date('Y-m-d H:i:s') . " - DOMAIN: " . $domain . fs_filehandler::NewLine() . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('bulwark_root') . 'modules/sencrypt/sencrypt.log');
			self::$certFailed = true;
			return false;
			exit(1);
		}

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . $certlocation . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . "/private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . "/chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port 			= 443;
			$portforward 	= 1;

			$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
			
			while ($row = $sql->fetch()) {
				$olddata = $row['vh_custom_tx'];
			}
				$data = $olddata. $line;
				
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					# For older Bulwark support
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx=:data, vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':portforward', $portforward);
					$sql->bindParam(':domain', $domain);
					$sql->execute();	
					
				} else {
					# For Bulwark 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port, vh_portforward_in=:portforward, vh_custom_port_in=:customport WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $data);
					$sql->bindParam(':port', $port);
					# added portforward & custom port for forwarding- tg
					//$sql->bindParam(':portforward', $portforward);
					//$sql->bindParam(':customport', $port);
					$sql->bindParam(':domain', $domain);
					$sql->execute();			
					
				}

				self::SetWriteApacheConfigTrue();
				self::$okletsencrypt = true;
				return true;
	}
	
	# Make SSL for Panel domain
	static function ExecuteMakePanelSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;
		// Security fix (June 2026): removed dead `$zsudo = ctrl_options::GetOption('zsudo');`.
		// See ExecuteMakeSSL for the rationale.
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
					
		$domainRoot = ctrl_options::GetSystemOption('bulwark_root');
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');
		$accountDir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		$certlocation = $accountDir . $panelDomain . "/";
		
		# Other panel subomains to include
		//$otherSubDomains = "ftp, webmail, mail, smtp, imap, pop";

		require("modules/sencrypt/code/Lescript.php");
		
		
		$user_ip = ctrl_options::GetSystemOption('server_ip');

		# Check DNS before continuing
		if (!self::checkDNSIsLive($domain, $user_ip)) {
			self::$dnsInvalid = true;
			return false;
		}

		# Make Let´s encrypt SSL
		$logger = new Logger();

		try {
			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger);
			
			# uses client's email used during registration
			$le->contact = array('mailto:' . $currentuser['email']); // optional
		
			# Init. Account and update account email to keep current.
			$le->initAccount();
			$le->postUpdateRegEmail();
			
			
			# Create panel SSL if no other sub domains are listed
			//if ($otherSubDomain == NULL ) {
			
				# Sign Panel domain 
				$le->signDomains(array($domain));
			


			// BETA CODE TO SEE IF WE CAN CONTROL DOMAIN SUB ATL CERTS (FTP, POP, SMTP, IMAP) in works!! Might not happen! Moving to Certbot for more control.
			/*
			} else {
			
				# Checks if domain is sub or root by checking how many dots(.) there are. Returns dot(.) count
				if (substr_count($domain, '.') == 2 ) {
					# If panel domain is a sub domain
					$removeNeedle = ".";
					$pos = strpos($domain, $removeNeedle);
					$rootDomain = substr($domain, $pos . ".");
					
				} else {
					# If panel domain is a root domain
					$rootDomain = "." . $domain;
				
				}
				
				# If other panel subdomains are listed include them in SSL cert
				if ($otherSubDomains != NULL) {
				 
					$str_arr = preg_split ("/\,/", $otherSubDomains);
					
					# Set subdomain array
					$subList = array("");
					
					# Push each subdomain domain into an array for SSL
					foreach ($str_arr as $value) {
						array_push($subList, ', ' . $value.$rootDomain);
					}
					
					# Put list into string format for letsencrypt
					$subFinalList = implode($subList);
					
				}
				
				# Sign panel domain plus other subdomain
				$le->signDomains(array($domain . $subFinalList));
				
			}
			*/
			
		}
		catch (\Exception $e) {
			$errorCatahed = $e->getMessage();
			$logger->error($e->getTraceAsString());
			$logger->error($e->getMessage());
			# Throw error and log to file
			error_log( date('Y-m-d H:i:s') . " - PANEL DOMAIN: " . $domain . fs_filehandler::NewLine() . $errorCatahed . fs_filehandler::NewLine(), 3, ctrl_options::GetSystemOption('bulwark_root') . 'modules/sencrypt/sencrypt.log');
			self::$certFailed = true;
			return false;
			exit(1);
		}

			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();			
			$line .= "SSLCertificateFile " . $certlocation . "cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . "private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . "chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port = 443;
			
			# new code down below
			# Update panel Port
			//$portname = "bulwark_port";
			//$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
			//$updatesql->bindParam(':value', $port);
			//$updatesql->bindParam(':name', $portname);
			//$updatesql->execute();

			# MAY HAVE ISSUE HERE.
# NEW CODE
			if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
				
				# Update panel Port
				$portname = "bulwark_port";
				$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
				$updatesql->bindParam(':value', $port);
				$updatesql->bindParam(':name', $portname);
				$updatesql->execute();
				
				# For older Bulwark support
				# Update panel SSL data
				$panel_so_name = "global_zpcustom";
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :data WHERE so_name_vc = :panel_so_name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':panel_so_name', $panel_so_name);
				
			} else {
				# For Bulwark 2.0
				# Update panel Port
				$portname = "bulwark_port";
				$updatesql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :value WHERE so_name_vc = :name");
				$updatesql->bindParam(':value', $port);
				$updatesql->bindParam(':name', $portname);
				$updatesql->execute();

				# update panel ssl data
				$panel_so_name = "panel_ssl_tx";
				$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = :data WHERE so_name_vc = :panel_so_name");
				$sql->bindParam(':data', $line);
				$sql->bindParam(':panel_so_name', $panel_so_name);
				
			}
# NEW CODE

		$sql->bindParam(':data', $line);
		$sql->execute();
			
		self::SetWriteApacheConfigTrue();
		self::$okletsencrypt = true;
		return true;
	}

	static function doDelete() {
        runtime_csfr::Protect();
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');

		$sub_module = "letsencrypt";
		
		if (self::ExecuteTPDelete($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}
/*
	static function ExecuteDelete($domain, $username) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$rootdir = str_replace('.', '_', $domain);
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $domain;
		$objects = scandir($dir);
		
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				 unlink($dir."/" . $object);
			}
		}
	
		rmdir($dir);

		$port = NULL;
		$new = NULL;

		$line = "# Lets Encrypt start" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		$line .= 'SSLEngine On' . fs_filehandler::NewLine();
		$line .= "SSLCertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/cert.pem" . fs_filehandler::NewLine();
		$line .= "SSLCertificateKeyFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/private.pem" . fs_filehandler::NewLine();
		$line .= "SSLCACertificateFile " . ctrl_options::GetSystemOption('hosted_dir') . $currentuser['username'] . "/ssl/sencrypt/letsencrypt/" . $domain . "/chain.pem" . fs_filehandler::NewLine();
		$line .= "SSLProtocol All -SSLv2 -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
		$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
		$line .= "SSLCipherSuite \"EECDH+ECDSA+AESGCM EECDH+aRSA+AESGCM EECDH+ECDSA+SHA384 EECDH+ECDSA+SHA256 EECDH+aRSA+SHA384 EECDH+aRSA+SHA256 EECDH+AESGCM EECDH EDH+AESGCM EDH+aRSA HIGH !MEDIUM !LOW !aNULL !eNULL !LOW !RC4 !MD5 !EXP !PSK !SRP !DSS\"" . fs_filehandler::NewLine();
		$line .= "# Lets Encrypt end" . fs_filehandler::NewLine();

		//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
		
		# NEW CODE
		if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
			# For older Bulwark support
			//$portforward 	= 1;
			
			$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx = replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
			//$sql->bindParam(':portforward', $portforward);
			
		} else {
			# For Bulwark 2.0
			$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx = replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
			
		}
		# NEW CODE

		$sql->bindParam(':data', $line);
		$sql->bindParam(':new', $new);
		$sql->bindParam(':domain', $domain);
		$sql->bindParam(':port', $port);
		$sql->execute();
		self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;
	}
*/
	# Delete Panel SSL		
	# do we need to pass panel domain since panel only uses one domain?
	static function doDeletePanelSSL() {
        runtime_csfr::Protect();
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		//if (self::ExecuteDeletePanelSSL($formvars['inDomain'], $currentuser["username"]))
		if (self::ExecuteDeletePanelSSL(ctrl_options::GetSystemOption('bulwark_domain'), $currentuser["username"]))
		return true;
	}
	
	# do we need to pass panel domain since panel only uses one domain?
	static function ExecuteDeletePanelSSL($panelDomain, $username) {
		global $zdbh;
		global $controller;
		$currentuser = ctrl_users::GetUserDetail();
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/";
		
		$sub_module = "letsencrypt";
		
		$dir = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/letsencrypt/" . $panelDomain;
		
		# delete cert files
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				 unlink($dir."/" . $object);
			}
		}
	
		rmdir($dir);

		$new = NULL;

		$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
		$line .= fs_filehandler::NewLine();
		$line .= 'SSLEngine On' . fs_filehandler::NewLine();
		$line .= "SSLProtocol All -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
		$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
		$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();		
		$line .= "SSLCertificateFile " . $certlocation . $panelDomain . "/cert.pem" . fs_filehandler::NewLine();
		$line .= "SSLCertificateKeyFile " . $certlocation . $panelDomain . "/private.pem" . fs_filehandler::NewLine();
		$line .= "SSLCACertificateFile " . $certlocation . $panelDomain . "/chain.pem" . fs_filehandler::NewLine();
		$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

# NEW CODE
		
		if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
			# For older Bulwark support
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :panel_ssl");
			$panelssltxt = "global_zpcustom";
			$sql->bindParam(':panel_ssl', $panelssltxt);
			
		} else {
			# For Bulwark 2.0
			$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx = replace(so_value_tx, :data, :new) WHERE so_name_vc = :panel_ssl");
			$panelssltxt = "panel_ssl_tx";
			$sql->bindParam(':panel_ssl', $panelssltxt);
		}

# NEW CODE

		$sql->bindParam(':data', $line);
		$sql->bindParam(':new', $new);
		$sql->execute();
	
		# change panel port back to 80
		$bulwark_port = 80;
		$so_name_vc = "bulwark_port";
		
		$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx=:bulwark_port WHERE so_name_vc = :so_name_vc");
		$panelssltxt = "panel_ssl_tx";
		$sql->bindParam(':bulwark_port', $bulwark_port);
		$sql->bindParam(':so_name_vc', $so_name_vc);
		$sql->execute();
		
		self::SetWriteApacheConfigTrue();
		self::$delok = true;
		return true;
	}

	/**
	 * Revoca el certificado SSL del PANEL en Let's Encrypt y limpia localmente.
	 * La revocación remota es best-effort (try/catch, sin exit): aunque falle,
	 * se limpia el cert y se restaura el panel a HTTP (reutiliza ExecuteDeletePanelSSL).
	 * Faltaba este handler: el botón "Revoke" del panel SSL no hacía nada.
	 */
	static function doRevokePanelSSL() {
		runtime_csfr::Protect();
		$currentuser = ctrl_users::GetUserDetail();
		$username    = $currentuser["username"];
		$panelDomain = ctrl_options::GetSystemOption('bulwark_domain');

		// Cuenta ACME COMPARTIDA (igual que la emisión, FIX-212); el cert del panel sigue en su home.
		$staging      = ctrl_options::GetSystemOption('le_staging') === 'true';
		$leSub        = $staging ? 'letsencrypt-staging' : 'letsencrypt';
		$accountDir   = $staging ? '/var/bulwark/ssl/sencrypt/letsencrypt-staging/' : '/var/bulwark/ssl/sencrypt/letsencrypt/';
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/" . $leSub . "/" . $panelDomain; // dir con cert.pem del panel
		$certFile     = $certlocation . "/cert.pem";

		// 1) Revocar en Let's Encrypt (best-effort: no romper el panel si falla)
		if (is_file($certFile)) {
			try {
				require_once("modules/sencrypt/code/Lescript.php");
				date_default_timezone_set("UTC");
				$logger  = new Logger();
				$pem2der = self::base64url(self::pem2der(file_get_contents($certFile)));
				$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $certlocation, $logger);
				if (ctrl_options::GetSystemOption("le_staging") === "true") { $le->setCaUrl("https://acme-staging-v02.api.letsencrypt.org"); }
					$le->initCommunication();
					$panelCertKey = $certlocation . "/private.pem";
					if (is_file($panelCertKey)) {
						$le->postRevokeByCertKey($pem2der, $panelCertKey);
					} else {
						$le->initAccount();
						$le->postRevoke($pem2der);
					}
			} catch (\Throwable $e) {
				// Log y continuar: la limpieza local se hace igualmente.
				error_log('sencrypt doRevokePanelSSL: fallo al revocar en LE: ' . $e->getMessage());
			}
		}

		// 2) Limpieza local + reconstrucción de config (misma lógica que "Delete panel SSL")
		self::ExecuteDeletePanelSSL($panelDomain, $username);
		self::$delok = true;
		return true;
	}

	static function doRevokeSSL() {
        runtime_csfr::Protect();
		global $controller;
		$sub_module = "letsencrypt";
		$currentuser = ctrl_users::GetUserDetail();
		$formvars = $controller->GetAllControllerRequests('FORM');
		
		if (self::ExecuteRevokeSSL($formvars['inDomain'], $currentuser["username"], $sub_module))
		return true;
	}

	static function ExecuteRevokeSSL($domain, $username, $sub_module) {
		global $zdbh, $controller;
		// Security fix (June 2026): removed dead `$zsudo = ctrl_options::GetOption('zsudo');`.
		// See ExecuteMakeSSL for the rationale.
		$currentuser = ctrl_users::GetUserDetail();
		$username = $currentuser["username"];
		$userid = $currentuser["userid"];
		// Revocación por la CUENTA ACME COMPARTIDA (la misma con la que se emite tras FIX-212); si se
		// usara la antigua cuenta por-usuario, LE rechazaría la revocación (no emitió el cert). Los
		// certificados siguen siendo por usuario. $certlocation es la base por-usuario SIN el dominio,
		// porque más abajo se le añade "$domain/..." (antes se duplicaba el dominio en la ruta).
		$staging      = ctrl_options::GetSystemOption('le_staging') === 'true';
		$leSub        = $staging ? 'letsencrypt-staging' : 'letsencrypt';
		$accountDir   = $staging ? '/var/bulwark/ssl/sencrypt/letsencrypt-staging/' : '/var/bulwark/ssl/sencrypt/letsencrypt/';
		$certlocation = ctrl_options::GetSystemOption('hosted_dir') . $username . "/ssl/sencrypt/" . $leSub . "/";
		$_vhp2 = ctrl_options::GetVhostPaths($username, str_replace('.', '_', $domain));
		$domainRoot = $_vhp2['public_html'];

		# Convert PEM cert to DER format base64url for revoke
		# tg
		$pem_data = file_get_contents($certlocation . $domain . "/cert.pem");
		$pem2der = self::base64url(self::pem2der($pem_data));
		

		require("modules/sencrypt/code/Lescript.php");
		date_default_timezone_set("UTC");
		
		$logger = new Logger();
		
		# Revoke Let´s encrypt SSL
		try {
			$le = new Analogic\ACME\Lescript($accountDir, $certlocation, $domainRoot, $logger);
			
			# uses client's email used during registration
			//$le->contact = array('mailto:' . $currentuser['email']); // optional
		
			if (ctrl_options::GetSystemOption("le_staging") === "true") { $le->setCaUrl("https://acme-staging-v02.api.letsencrypt.org"); }
			$le->initCommunication();
			$certKey = $certlocation . $domain . "/private.pem";
			if (is_file($certKey)) {
				# Robusto: revocar con la CLAVE del propio certificado (RFC 8555; no depende de la cuenta emisora).
				$le->postRevokeByCertKey($pem2der, $certKey);
			} else {
				# Fallback: revocar con la cuenta ACME compartida.
				$le->initAccount();
				$le->postRevoke($pem2der);
			}
		}
		
		catch (\Exception $e) {
			$logger->error($e->getMessage());
			$logger->error($e->getTraceAsString());
			exit(1);
		}
		
		# Delete Letsencrypt Cert from DB & System
		
			$line = "# Made from Sencrypt - " . $sub_module . " - start" . fs_filehandler::NewLine();
			$line .= fs_filehandler::NewLine();
			$line .= 'SSLEngine On' . fs_filehandler::NewLine();
			$line .= "SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1" . fs_filehandler::NewLine();
			$line .= "SSLHonorCipherOrder on" . fs_filehandler::NewLine();
			$line .= "SSLCipherSuite \"ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384\"" . fs_filehandler::NewLine();
			$line .= "SSLCertificateFile " . $certlocation . $domain . "/cert.pem" . fs_filehandler::NewLine();
			$line .= "SSLCertificateKeyFile " . $certlocation . $domain . "/private.pem" . fs_filehandler::NewLine();
			$line .= "SSLCACertificateFile " . $certlocation . $domain . "/chain.pem" . fs_filehandler::NewLine();
			$line .= "# Made from Sencrypt - " . $sub_module . " - end" . fs_filehandler::NewLine();

			$port 			= NULL;
			$portforward 	= NULL;
			$new = '';

			$sql = $zdbh->prepare("SELECT * FROM x_vhosts WHERE vh_name_vc = :domain AND vh_deleted_ts IS NULL");
			$sql->bindParam(':domain', $domain);
			$sql->execute();
			
			while ($row = $sql->fetch()) {
				$olddata = $row['vh_custom_tx'];
			}
			
				$data = $olddata. $line;
				
				//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
			
				# NEW CODE
				if ( ctrl_options::GetSystemOption('dbversion') <= "1.0.3") {
					# For older Bulwark support						
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx=:data, vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx= replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port WHERE vh_name_vc = :domain");
					//$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_custom_tx= replace(vh_custom_tx, :data, :new), vh_custom_port_in=:port, vh_portforward_in=:portforward WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':domain', $domain);
					$sql->bindParam(':portforward', $portforward);
					$sql->execute();	
					
				} else {
					# For Bulwark 2.0
					$sql = $zdbh->prepare("UPDATE x_vhosts SET vh_ssl_tx= replace(vh_ssl_tx, :data, :new), vh_ssl_port_in=:port WHERE vh_name_vc = :domain");
					$sql->bindParam(':data', $line);
					$sql->bindParam(':new', $new);
					$sql->bindParam(':port', $port);
					$sql->bindParam(':domain', $domain);
					$sql->execute();

				}
				# NEW CODE END
				

				//$sql->bindParam(':data', $data);
				//$sql->bindParam(':domain', $domain);
				//$sql->bindParam(':port', $port);
				//$sql->bindParam(':portforward', $portforward);
				//$sql->execute();			

			self::SetWriteApacheConfigTrue();
			self::$revokecert = true;
			return true;
	}

	static function pem2der($pem) {
		return base64_decode(implode('',array_slice(
			array_map('trim',explode("\n",trim($pem))),1,-1
		)));
	}

	static function base64url($data){ # RFC7515 - Appendix C
		return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
	}
	
# LETS Encrypt code - END

	static function SetWriteApacheConfigTrue() {
		global $zdbh;
		$sql = $zdbh->prepare("UPDATE x_settings SET so_value_tx='true'	WHERE so_name_vc='apache_changed'");
		$sql->execute();
	}

    //static function getPortWarning() {
		
		//return '<font face="ariel" size="4">' . ui_language::translate("ZADMIN ALERT: Make sure port 443 is OPEN before adding any SSL certificates!") . '</font>';
    //}
	
	static function checkDNSIsLive($domain, $server_ip) {
		if (!checkdnsrr($domain, "A")) {
			return false;
		}
		$records = dns_get_record($domain, DNS_A);
		if (empty($records)) {
			return false;
		}
		foreach ($records as $record) {
			if (isset($record['ip']) && $record['ip'] === $server_ip) {
				return true;
			}
		}
		return false;
	}
	

	static function getResult() {
		if (self::$modReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("You need at least PHP 5.3.0+ with OpenSSL and curl extension installed. Contact your admin for help. This Module may not work correctly until the issues are fixed."), "zannounceerror");
		}
		if (self::$portReqsError)
		{
			return ui_sysmessage::shout(ui_language::translate("ALERT: Port (443) appears to be CLOSED. Sencrypt will not work until port (443) is OPEN. Contact your Administrator."), "zannounceerror");
		}
		if (self::$okletsencrypt)
		{
			return ui_sysmessage::shout(ui_language::translate("Your FREE Letsencrypt SSL Certificate has been Created. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$okselfsigned)
		{
			return ui_sysmessage::shout(ui_language::translate("Your Self-Signed SSL Certificate has been Created. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$okthirdparty)
		{
			return ui_sysmessage::shout(ui_language::translate("Your Third-Party SSL Certificate has been uploaded. It will be active in about 5 minutes."), "zannounceok");
		}
		if (self::$delok)
		{
			return ui_sysmessage::shout(ui_language::translate("The selected certificate has been deleted."), "zannounceok");
		}
		if (self::$error)
		{
			return ui_sysmessage::shout(ui_language::translate("A certificate with that name already exists."), "zannounceerror");
		}
		if (self::$dnsInvalid){
			return ui_sysmessage::shout(ui_language::translate("Your DNS for this domain has not Propagated yet. Takes (24 to 48/hrs) or DNS is not POINTING to this server yet. Please check your DNS and retry again later."), "zannounceerror");
		}
		if (self::$revokecert) {
            return ui_sysmessage::shout(ui_language::translate("The Requested Certificate has been revoked"), "zannounceok");
        }
		if (self::$keyadd) {
            return ui_sysmessage::shout(ui_language::translate("Certificate Signing Request was made and sent to the mail you have entered"), "zannounceok");
        }
		if (self::$certFailed) {
            return ui_sysmessage::shout(ui_language::translate("Oops! Something went wrong! Your Lets Encrypt certificate was not created. Please check if your DNS Propagated. Takes (24 to 48/hrs) or DNS is not POINTING to this server yet. Please check your DNS and retry again later. If this error continues after 72 hours, contact your administrator."), "zannounceerror");
        }
		return;
	}
}
?>
