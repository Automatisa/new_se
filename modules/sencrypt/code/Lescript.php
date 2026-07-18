<?php
 
namespace Analogic\ACME;

use \RuntimeException;
use \Psr\Log\LoggerInterface;

class Lescript
{
    public $ca = 'https://acme-v02.api.letsencrypt.org'; // PRODUCTION ONLY
    //public $ca = 'https://acme-staging-v02.api.letsencrypt.org'; // TESTING ONLY!!!!!
    public $countryCode;
    public $state;
    public $challenge = 'http-01'; # http-01 challange only
    public $contact = array(); # optional

	public $clientUserAgent = "analogic-lescript/0.3.0";
	
    protected $certificatesDir;
    protected $webRootDir;

    /** @var LoggerInterface */
    protected $logger;
    /** @var ClientInterface */
    protected $client;
    protected $accountKeyPath;

    protected $accountId = '';
    protected $urlNewAccount = '';
    protected $urlNewNonce = '';
    protected $urlNewOrder = '';
    protected $urlRenewalInfo = ''; // ARI (draft-ietf-acme-ari): endpoint renewalInfo del directorio
	
	# NEW CODE - tg - Added counntry code and state
    # PHP 8.x fix: optional parameters must follow mandatory ones, so we
    # default $countryCode/$state to NULL and keep backward-compatible order.
    public function __construct($accountDir, $certificatesDir, $webRootDir, $logger = NULL, $countryCode = NULL, $state = NULL, ClientInterface $client = NULL)
    {
		$this->accountDir = $accountDir;
        $this->certificatesDir = $certificatesDir;
        $this->webRootDir = $webRootDir;
        $this->logger = $logger;
		$this->countryCode = $countryCode;
		$this->state = $state;
        $this->client = $client ? $client : new Client($this->ca, $this->clientUserAgent);
        $this->accountKeyPath = $accountDir . '_account/private.pem';
    }

    # Conmuta la CA ACME (p.ej. a STAGING para pruebas sin gastar límites de producción ni arriesgar
    # bloqueo de IP). Debe llamarse ANTES de initAccount()/initCommunication(). Staging usa una cuenta
    # y certificados de una raíz de PRUEBAS (no confiada por navegadores) — solo para validar el flujo.
    public function setCaUrl($url)
    {
        $this->ca = $url;
        $this->client = new Client($this->ca, $this->clientUserAgent);
    }

    public function initAccount()
    {
        $this->initCommunication();

        if (!is_file($this->accountKeyPath)) {

            # generate and save new private key for account
            # ---------------------------------------------
            $this->log('Starting new account registration');
            $this->generateKey(dirname($this->accountKeyPath));
            $this->postNewReg();
            $this->log('New account certificate registered');
			
        } else {
            $this->log('Account already registered. Continuing.');
            $this->getAccountId();
        }

        if (empty($this->accountId)) {
            throw new RuntimeException("We don't have account ID");
        }

        $this->log("Account: " . $this->accountId);
    }

    public function initCommunication()
    {
		$this->log('ACME Client: '.$this->clientUserAgent);
        $this->log('Getting list of URLs for API');

        $directory = $this->client->get('/directory');
        if (!isset($directory['newNonce']) || !isset($directory['newAccount']) || !isset($directory['newOrder']) || !isset($directory['revokeCert']) ) {
            throw new RuntimeException("Missing setup urls");
        }

        $this->urlNewNonce = $directory['newNonce'];
        $this->urlNewAccount = $directory['newAccount'];
        $this->urlNewOrder = $directory['newOrder'];
		$this->urlRevokeCert = $directory['revokeCert'];
		# ARI: endpoint opcional; si el directorio no lo publica, queda vacío y ARI se desactiva solo.
		$this->urlRenewalInfo = isset($directory['renewalInfo']) ? $directory['renewalInfo'] : '';

        $this->log('Requesting new nonce for client communication');
        $this->client->get($this->urlNewNonce);
    }

    # ARI (draft-ietf-acme-ari): calcula el "certID" de un certificado ya emitido, que es
    # base64url(AKI keyIdentifier) . "." . base64url(número de serie). Se usa tanto para consultar
    # renewalInfo como para el campo `replaces` de la orden. Devuelve '' si no se puede calcular
    # (así el llamador degrada a la lógica de 30 días sin romper nada).
    public function getAriCertID($certPath)
    {
        if (!is_file($certPath)) return '';
        $pem = @file_get_contents($certPath);
        if ($pem === false) return '';
        $c = @openssl_x509_parse($pem);
        if (!is_array($c)) return '';
        # AKI: extraer la secuencia hex "AB:CD:.." del authorityKeyIdentifier
        $akiRaw = isset($c['extensions']['authorityKeyIdentifier']) ? $c['extensions']['authorityKeyIdentifier'] : '';
        if (!preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2})+)/', $akiRaw, $m)) return '';
        $akiHex = str_replace(':', '', $m[1]);
        # Serie: hex de openssl (magnitud del INTEGER); asegurar longitud par
        $serialHex = isset($c['serialNumberHex']) ? preg_replace('/[^0-9A-Fa-f]/', '', $c['serialNumberHex']) : '';
        if ($serialHex === '') return '';
        if (strlen($serialHex) % 2 !== 0) { $serialHex = '0' . $serialHex; }
        $akiBin = @hex2bin($akiHex);
        $serBin = @hex2bin($serialHex);
        if ($akiBin === false || $serBin === false) return '';
        # ARI/RFC 9773: la serie son los octetos de VALOR del INTEGER DER. Para un entero positivo con
        # el bit alto del primer octeto activo, DER antepone un 0x00 de signo. (Si openssl ya lo trae,
        # el primer octeto sería 0x00 -> bit alto a 0 -> no se duplica.)
        if (strlen($serBin) > 0 && (ord($serBin[0]) & 0x80)) { $serBin = "\x00" . $serBin; }
        return Base64UrlSafeEncoder::encode($akiBin) . '.' . Base64UrlSafeEncoder::encode($serBin);
    }

    # ARI: consulta la ventana de renovación sugerida para un certID. Devuelve
    # ['start'=>ts, 'end'=>ts, 'explanationURL'=>str] o NULL si no hay ARI/da error (fallback 30 días).
    public function getRenewalInfo($certID)
    {
        if ($this->urlRenewalInfo === '' || !is_string($certID) || $certID === '') return null;
        try {
            $resp = $this->client->get($this->urlRenewalInfo . '/' . $certID);
        } catch (\Exception $e) {
            return null;
        }
        if (!is_array($resp) || empty($resp['suggestedWindow']['start']) || empty($resp['suggestedWindow']['end'])) return null;
        $start = strtotime($resp['suggestedWindow']['start']);
        $end   = strtotime($resp['suggestedWindow']['end']);
        if ($start === false || $end === false) return null;
        return array('start' => $start, 'end' => $end, 'explanationURL' => isset($resp['explanationURL']) ? $resp['explanationURL'] : '');
    }

    # $challengeType: 'http-01' (por defecto) o 'dns-01'. Para dns-01 (obligatorio en WILDCARDS
    # *.dominio) se pasan callbacks: $dnsProvision($recordName,$txtValue) crea el TXT en
    # _acme-challenge.<dominio> y espera propagación; $dnsCleanup($recordName,$txtValue) lo borra.
    public function signDomains(array $domains, $reuseCsr = false, $replaces = '', $challengeType = 'http-01', $dnsProvision = null, $dnsCleanup = null)
    {
        $this->log('Starting certificate generation process for domains');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        # start domains authentication
        # ----------------------------
        $this->log("Requesting challenge for ".join(', ', $domains));
        $orderPayload = array("identifiers" => array_map(
            function ($domain) {
                return array("type" => "dns", "value" => $domain);
            },
            $domains
            ));
        # ARI (draft-ietf-acme-ari): si nos pasan el certID del cert que se reemplaza, se incluye en
        # la orden para que Let's Encrypt trate esta emisión como RENOVACIÓN (exenta de rate-limits).
        if (is_string($replaces) && $replaces !== '') {
            $orderPayload['replaces'] = $replaces;
        }
        $response = $this->signedRequest($this->urlNewOrder, $orderPayload);

        $finalizeUrl = $response['finalize'];

        foreach ($response['authorizations'] as $authz) {
            # 1. getting authentication requirements
            # --------------------------------------
            $response = $this->signedRequest($authz, "");
            $domain = $response['identifier']['value'];
            if (empty($response['challenges'])) {
                throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));
            }

            $self = $this;
            $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self, $challengeType) {
                return $v ? $v : ($w['type'] == $challengeType ? $w : false);
            });
            if (!$challenge) throw new RuntimeException("Challenge $challengeType for $domain is not available. Whole response: " . json_encode($response));

            $this->log("Got challenge token for $domain ($challengeType)");

            # 2. exponer la autorización (keyAuthorization = token.thumbprint)
            # ---------------------------------------------------------------
            $header = array(
                # need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

            $tokenPath = null; $dnsRecordName = null; $dnsTxtValue = null;
            if ($challengeType === 'dns-01') {
                # DNS-01: el valor TXT es base64url(sha256(keyAuthorization)); va en
                # _acme-challenge.<dominio> (para *.dominio se quita el comodín). Provisionar y esperar.
                $dnsTxtValue  = Base64UrlSafeEncoder::encode(hash('sha256', $payload, true));
                $baseDomain   = preg_replace('/^\*\./', '', $domain);
                $dnsRecordName = '_acme-challenge.' . $baseDomain;
                if (!is_callable($dnsProvision)) {
                    throw new RuntimeException("dns-01 requiere un callback de provisión de TXT.");
                }
                $this->log("DNS-01: provisionando $dnsRecordName TXT ...");
                call_user_func($dnsProvision, $dnsRecordName, $dnsTxtValue);
            } else {
                # HTTP-01: escribir el token en el webroot.
                $directory = $this->webRootDir . '/.well-known/acme-challenge';
                $tokenPath = $directory . '/' . $challenge['token'];
                if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                    throw new RuntimeException("Couldn't create directory to expose challenge: " . $tokenPath);
                }
                file_put_contents($tokenPath, $payload);
                chmod($tokenPath, 0644);
                $this->log("Token for $domain saved at $tokenPath");
            }

            # 3. verification process itself
            # -------------------------------
            $this->log("Sending request to challenge");
                
            # send request to challenge
            $maxAllowedLoops = 6;
            $loopCount = 1;
            $result = null;
            while ($loopCount < $maxAllowedLoops) {
                $result = $this->signedRequest(
                    $challenge['url'],
                    array("keyAuthorization" => $payload)
                );

                if (empty($result['status']) || $result['status'] == "invalid") {
                    throw new RuntimeException("Verification ended with error: " . json_encode($result));
                }

                if ($result['status'] != "pending") {
                    break;
                }

                $sleepTime = $loopCount * $loopCount; // 1 4 9 16 25 36
                $loopCount++;

                $this->log("Verification pending, sleeping " . $sleepTime . "s");
                sleep($sleepTime);
            }

            if ($result['status'] === "pending") {
                throw new RuntimeException("Verification timed out");
            }

            $this->log("Verification ended with status: " . $result['status']);

            if ($challengeType === 'dns-01') {
                if (is_callable($dnsCleanup)) { call_user_func($dnsCleanup, $dnsRecordName, $dnsTxtValue); }
            } else {
                @unlink($tokenPath);
            }
        }

        # requesting certificate
        # ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        # generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        # load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr") ?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

        $finalizeResponse = $this->signedRequest($finalizeUrl, array('csr' => $csr));

        if ($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200) {
            throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($finalizeResponse));
        }
        
        $maxAllowedLoops = 6;
        $loopCount = 1;
		
		$lastLocationUrl = $this->client->getLastLocation();
		
		
        while ($loopCount < $maxAllowedLoops) {
            $this->log("Firing Order Status Request Nr. " . $loopCount . " to: " . $lastLocationUrl);
            $OrderStatusResponse = $this->signedRequest($lastLocationUrl, "");

            if (($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200)) {
                throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($OrderStatusResponse));
            }

            if (($OrderStatusResponse['status'] == "valid" && !empty($OrderStatusResponse['certificate']))) {
                $this->log("Order Status: " . $OrderStatusResponse['status']);
                $location = $OrderStatusResponse['certificate'];
                break;
            }

            $sleepTime = $loopCount * $loopCount; // 1 4 9 16 25 36
            $loopCount++;

            $this->log("Order Status not 'valid' yet but '" . $OrderStatusResponse['status'] . "', sleeping " . $sleepTime . "s");
            sleep($sleepTime);
        }

        if (empty($location)) {
            throw new RuntimeException("Certificate generation processing timed out (Status not 'valid')");
        }

        # waiting loop
        $certificates = array();
        while (1) {
            $this->client->getLastLinks();

            $result = $this->signedRequest($location, "");

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->log("Got certificate! YAY!");
                $serverCert = $this->parseFirstPemFromBody($result);
                $certificates[] = $serverCert;
                $certificates[] = substr($result, strlen($serverCert)); # rest of ca certs

                break;
            } else {

                throw new RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

            }
        }

        if (empty($certificates)) throw new RuntimeException('No certificates generated');

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

    protected function readPrivateKey($path)
    {
        if (($key = openssl_pkey_get_private('file://' . $path)) === FALSE) {
            throw new RuntimeException(openssl_error_string());
        }

        return $key;
    }

    protected function parseFirstPemFromBody($body)
    {
        preg_match('~(-----BEGIN.*?END CERTIFICATE-----)~s', $body, $matches);

        return $matches[1];
    }

    protected function getDomainPath($domain)
    {
        //tg return $this->certificatesDir . '/' . $domain . '/';
		return $this->certificatesDir;
    }

    protected function getAccountId()
    {
        return $this->postNewReg();
    }

    protected function postNewReg()
    {
        $data = array(
            'termsOfServiceAgreed' => true
        );

        $this->log('Sending registration to letsencrypt server');

        if ($this->contact) {
            $data['contact'] = $this->contact;
        }

        $response = $this->signedRequest(
            $this->urlNewAccount,
            $data
        );
        $lastLocation = $this->client->getLastLocation();
        if (!empty($lastLocation)) {
            $this->accountId = $lastLocation;
        }
        return $response;
    }

    protected function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) {
            return "DNS:" . $dns;
        }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta = stream_get_meta_data($tmpConf);
        $tmpConfPath = $tmpConfMeta["uri"];

        # workaround to get SAN working
        fwrite($tmpConf,
            'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        $csr = openssl_csr_new(
            array(
                "CN" => $domain,
                "ST" => $this->state,
                "C" => $this->countryCode,
                "O" => "Unknown",
            ),
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) throw new RuntimeException("CSR couldn't be generated! " . openssl_error_string());

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        $csrPath = $this->getDomainPath($domain) . "/last.csr";
        file_put_contents($csrPath, $csr);

        return $this->getCsrContent($csrPath);
    }

    protected function getCsrContent($csrPath) {
        $csr = file_get_contents($csrPath);

        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

    protected function generateKey($outputDirectory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if (!openssl_pkey_export($res, $privateKey)) {
            throw new RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if (!is_dir($outputDirectory)) @mkdir($outputDirectory, 0700, true);
        if (!is_dir($outputDirectory)) throw new RuntimeException("Cant't create directory $outputDirectory");

        file_put_contents($outputDirectory . '/private.pem', $privateKey);
        file_put_contents($outputDirectory . '/public.pem', $details['key']);
    }

    protected function signedRequest($uri, $payload, $nonce = null)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $this->accountId;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" : str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending signed request to $uri");

        return $this->client->post($uri, json_encode($data));
    }

    protected function log($message)
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            echo $message . "\n";
        }
    }
	
	################################################################### 
	
	
	public function postUpdateRegEmail()
    {

        $data = array(
			'contact' => $this->contact
		);

        $this->log('Requesting to update Email on account...');

        $response = $this->updateRequest(
			$this->accountId,
            $data
        );
        $lastLocation = $this->client->getLastLocation();
        if (!empty($lastLocation)) {
            $this->accountId = $lastLocation;
        }
        return $response;
    }

    public function updateRequest($uri, $payload, $nonce = null)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $uri;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" : str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

		#FOR TESTING
		//$sendDATA = array(
			//"URL" => $uri,
			//"payload" => $payload,
			//"Protected" =>	$protected
		//);

		$this->log("Sending request to update account email...");
		# TESTING ONLY
		//echo print_r($sendDATA);
		
        return $this->client->post($uri, json_encode($data));
		
		//$this->log("Request accepted. Email Updated.");
		
    }
	
	
	function postRevoke($certData) {

        # Se revoca SIN código de razón -> Let's Encrypt asume "unspecified" (0), que es lo correcto
        # para una revocación normal iniciada por el usuario. NO usar keyCompromise (1): LE BLOQUEA
        # esa clave para siempre; reservado a compromiso real de la clave privada (doc de revoking).
        $data = array(
            'certificate' => $certData
        );

        $this->log('Sending request to revoke certificate');

        $response = $this->revokeRequest(
            $this->urlRevokeCert,
            $data
        );
        //$lastLocation = $this->client->getLastLocation();
        //if (!empty($lastLocation)) {
           // $this->accountId = $lastLocation;
        //}
        return $response;
    }
	
	
	function revokeRequest($uri, $payload, $nonce = null) {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $this->accountId;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" :  json_encode($payload));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending revoke cert request to $uri");

        return $this->client->post($uri, json_encode($data));
    }

	# Revocación por la CLAVE DEL PROPIO CERTIFICADO (RFC 8555 §7.6): funciona sin importar qué cuenta
	# emitió el cert (útil para certs heredados de otras cuentas). No requiere initAccount(); solo
	# initCommunication() para tener el directorio y un nonce. $certData = DER base64url del cert;
	# $certKeyPath = ruta a la clave privada del cert (private.pem). Sin código de razón = unspecified.
	public function postRevokeByCertKey($certData, $certKeyPath)
	{
		if (!is_file($certKeyPath)) {
			throw new RuntimeException("No existe la clave del certificado para revocar: $certKeyPath");
		}
		$data = array('certificate' => $certData);
		return $this->revokeRequestWithKey($this->urlRevokeCert, $data, $certKeyPath);
	}

	# Igual que revokeRequest pero firmando con una clave ARBITRARIA (la del certificado) y usando
	# SIEMPRE cabecera 'jwk' (nunca 'kid'): así la autoriza la posesión de la clave del cert, no la
	# cuenta. Soporta RSA (lo que emite este módulo).
	public function revokeRequestWithKey($uri, $payload, $keyPath, $nonce = null)
	{
		$privateKey = $this->readPrivateKey($keyPath);
		$details    = openssl_pkey_get_details($privateKey);
		if (!isset($details["rsa"])) {
			throw new RuntimeException("La clave del certificado no es RSA; revocación por clave no soportada.");
		}
		$protected = array(
			"alg"   => "RS256",
			"nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
			"url"   => $uri,
			"jwk"   => array(
				"kty" => "RSA",
				"n"   => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
				"e"   => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
			),
		);
		$payload64   = Base64UrlSafeEncoder::encode(empty($payload) ? "" : json_encode($payload));
		$protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));
		openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, "SHA256");
		$signed64 = Base64UrlSafeEncoder::encode($signed);
		$data = array('protected' => $protected64, 'payload' => $payload64, 'signature' => $signed64);
		$this->log("Sending revoke-by-cert-key request to $uri");
		return $this->client->post($uri, json_encode($data));
	}

}

interface ClientInterface
{
    /**
     * Constructor
     *
     * @param string $base the ACME API base all relative requests are sent to
	 * @param string $userAgent ACME Client User-Agent
     */
    public function __construct($base, $userAgent);
    /**
     * Send a POST request
     *
     * @param string $url URL to post to
     * @param array $data fields to sent via post
     * @return array|string the parsed JSON response, raw response on error
     */
    public function post($url, $data);
    /**
     * @param string $url URL to request via get
     * @return array|string the parsed JSON response, raw response on error
     */
    public function get($url);
    /**
     * Returns the Replay-Nonce header of the last request
     *
     * if no request has been made, yet. A GET on $base/directory is done and the
     * resulting nonce returned
     *
     * @return mixed
     */
    public function getLastNonce();
    /**
     * Return the Location header of the last request
     *
     * returns null if last request had no location header
     *
     * @return string|null
     */
    public function getLastLocation();
    /**
     * Return the HTTP status code of the last request
     *
     * @return int
     */
    public function getLastCode();
    /**
     * Get all Link headers of the last request
     *
     * @return string[]
     */
    public function getLastLinks();
}

class Client implements ClientInterface
{
    protected $lastCode;
    protected $lastHeader;

    protected $base;
	protected $userAgent;

    public function __construct($base, $userAgent)
    {
        $this->base = $base;
		$this->userAgent = $userAgent;
    }

    protected function curl($method, $url, $data = null)
    {
        $headers = array('Accept: application/json', 'Content-Type: application/jose+json');
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, preg_match('~^http~', $url) ? $url : $this->base . $url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADER, true);
		curl_setopt($handle, CURLOPT_USERAGENT, $this->userAgent);

        # DO NOT DO THAT!
        // curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
        // curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
                break;
        }
        $response = curl_exec($handle);

        if (curl_errno($handle)) {
            throw new RuntimeException('Curl: ' . curl_error($handle));
        }

        $header_size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        $this->lastHeader = $header;
        $this->lastCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($this->lastCode >= 400 && $this->lastCode < 600) {
            throw new RuntimeException($this->lastCode . "\n".$body);
        }

        $data = json_decode($body, true);
        return $data === null ? $body : $data;
    }

    public function post($url, $data)
    {
        return $this->curl('POST', $url, $data);
    }

    public function get($url)
    {
        return $this->curl('GET', $url);
    }

    public function getLastNonce()
    {
        if (preg_match('~Replay-Nonce: (.+)~i', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }
        
        throw new RuntimeException("We don't have nonce");
    }

    public function getLastLocation()
    {
        if (preg_match('~Location: (.+)~i', $this->lastHeader, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getLastCode()
    {
        return $this->lastCode;
    }

    public function getLastLinks()
    {
        preg_match_all('~Link: <(.+)>;rel="up"~', $this->lastHeader, $matches);
        return $matches[1];
    }
}

class Base64UrlSafeEncoder
{
    public static function encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    public static function decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
