<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Addendum\ReflectionAnnotatedClass;
use DateTime;
use Exception;
use Nadybot\Core\Annotations\HttpGet;
use Nadybot\Core\Annotations\HttpPost;
use Nadybot\Core\{
	AsyncHttp,
	CommandReply,
	DB,
	Http,
	HttpResponse,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	Socket,
	Timer,
	Socket\AsyncSocket,
};
use Nadybot\Modules\WEBSERVER_MODULE\Migrations\ApiKey;

/**
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'webauth',
 *		accessLevel = 'mod',
 *		description = 'Pre-authorize Websocket connections',
 *		help        = 'webauth.txt'
 *	)
 *
 * @Instance
 */
class WebserverController {
	public const AUTH_AOAUTH = "aoauth";
	public const AUTH_BASIC = "webauth";

	/** Set by the registry */
	public string $moduleName;

	protected $serverSocket;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Socket $socket;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	protected array $routes = ['get' => [], 'post' => [], 'put' => [], 'delete' => []];

	/** @var array */
	protected array $authentications = [];

	protected AsyncSocket $asyncSocket;

	protected ?string $aoAuthPubKey = null;
	protected AsyncHttp $aoAuthPubKeyRequest;

	/**
	 * @Event("connect")
	 * @Description("Download aoauth public key")
	 */
	public function downloadPublicKey(): void {
		if ($this->settingManager->getString('webserver_auth') !== static::AUTH_AOAUTH) {
			return;
		}
		$aoAuthKeyUrl = rtrim(
			$this->settingManager->getString('webserver_aoauth_url'),
			'/'
		) . '/key';
		if (isset($this->aoAuthPubKeyRequest)) {
			$this->aoAuthPubKeyRequest->abortWithMessage("Not needed anymore");
		}
		$this->aoAuthPubKeyRequest = $this->http->get($aoAuthKeyUrl)
			->withTimeout(30)
			->withCallback(function (HttpResponse $response): void {
				unset($this->aoAuthPubKeyRequest);
				$this->receiveAoAuthPubkey($response);
			});
	}

	protected function receiveAoAuthPubkey(HttpResponse $response): void {
		if (isset($response->error) || $response->headers['status'] !== "200") {
			$this->logger->log('ERROR', 'Error downloading aoauth pubkey.');
			return;
		}
		$this->aoAuthPubKey = $response->body;
	}

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'webserver',
			'Enable webserver',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'superadmin'
		);

/*
		$this->settingManager->add(
			$this->moduleName,
			'webserver_certificate',
			'Path to the SSL/TLS certificate',
			'edit',
			'text',
			'',
			'',
			'',
			'superadmin'
		);
*/

		$this->settingManager->add(
			$this->moduleName,
			'webserver_port',
			'On which port does the HTTP server listen',
			'edit',
			'number',
			'8080',
			'',
			'',
			'superadmin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'webserver_addr',
			'Where to listen for HTTP requests',
			'edit',
			'text',
			'127.0.0.1',
			'127.0.0.1;0.0.0.0',
			'',
			'superadmin'
		);

/*
		$this->settingManager->add(
			$this->moduleName,
			'webserver_tls',
			'Use SSL/TLS for the webserver',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'superadmin'
		);
*/

		$this->settingManager->add(
			$this->moduleName,
			'webserver_auth',
			'How to authenticate against the webserver',
			'edit',
			'options',
			static::AUTH_BASIC,
			join(";", [static::AUTH_BASIC, static::AUTH_AOAUTH])
		);

		$this->settingManager->add(
			$this->moduleName,
			'webserver_base_url',
			'Which is the base URL for the webserver? This is where aoauth redirects to',
			'edit',
			'text',
			'default',
			'default',
			'',
			'admin',
			'webserver_base_url.txt'
		);

		$this->settingManager->add(
			$this->moduleName,
			'webserver_aoauth_url',
			'If you are using aoauth to authenticate: URL of the server',
			'edit',
			'text',
			'https://aoauth.org',
			'https://aoauth.org'
		);

		$this->scanRouteAnnotations();
		if ($this->settingManager->getBool('webserver')) {
			$this->listen();
		}
		$this->settingManager->registerChangeListener('webserver', [$this, "webserverMainSettingChanged"]);
		$this->settingManager->registerChangeListener('webserver_port', [$this, "webserverSettingChanged"]);
		$this->settingManager->registerChangeListener('webserver_addr', [$this, "webserverSettingChanged"]);
		$this->settingManager->registerChangeListener('webserver_auth', [$this, "downloadNewPublicKey"]);
		$this->settingManager->registerChangeListener('webserver_aoauth_url', [$this, "downloadNewPublicKey"]);
/*
		$this->settingManager->registerChangeListener('webserver_tls', [$this, "webserverSettingChanged"]);
		$this->settingManager->registerChangeListener('webserver_certificate', [$this, "webserverSettingChanged"]);
*/
	}

	/**
	 * Start or stop the webserver if the setting changed
	 */
	public function downloadNewPublicKey(string $settingName, string $oldValue, string $newValue): void {
		$this->timer->callLater(0, [$this, "downloadPublicKey"]);
	}

	/**
	 * Start or stop the webserver if the setting changed
	 */
	public function webserverMainSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if ($newValue === '1') {
			$this->listen();
		} else {
			$this->shutdown();
		}
	}

	/**
	 * Restart the webserver on the new port if the setting changed
	 */
	public function webserverSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if (!$this->settingManager->getBool('webserver')) {
			return;
		}
		$this->shutdown();
		$this->timer->callLater(0, [$this, "listen"]);
	}

	/**
	 * Authenticate player $player to login to the Webserver for $duration seconds
	 */
	public function authenticate(string $player, int $duration=3600): string {
		do {
			$uuid = bin2hex(random_bytes(12));
		} while (isset($this->authentications[$uuid]));
		$this->authentications[$player] = [$uuid, time() + $duration];
		return $uuid;
	}

	/**
	 * @HandlesCommand("webauth")
	 * @Matches("/^webauth$/")
	 */
	public function webauthCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uuid = $this->authenticate($sender, 3600);
		$msg = "You can now authenticate to the Webserver for 1h with the ".
			"credentials <highlight>{$uuid}<end>.";
		$sendto->reply($msg);
	}

	/**
	 * Scan all Instances for @HttpHet or @HttpPost annotations and register them
	 * @return void
	 */
	public function scanRouteAnnotations(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$reflection = new ReflectionAnnotatedClass($instance);
			foreach ($reflection->getMethods() as $method) {
				/** @var \Addendum\ReflectionAnnotatedMethod $method */
				foreach (["HttpGet", "HttpPost", "HttpPut", "HttpDelete", "HttpPatch"] as $annoName) {
					if (!$method->hasAnnotation($annoName)) {
						continue;
					}
					foreach ($method->getAllAnnotations($annoName) as $annotation) {
						/** @var HttpGet|HttpPost|HttpPut|HttpDelete|HttpPatch $annotation */
						if (isset($annotation->value)) {
							$this->addRoute($annotation->type, $annotation->value, $method->getClosure($instance));
						}
					}
				}
			}
		}
	}


	/**
	 * Add a HTTP route handler for a path
	 */
	public function addRoute(string $method, string $path, callable $callback): void {
		$route = $this->routeToRegExp($path);
		if (!isset($this->routes[$method][$route])) {
			$this->routes[$method][$route] = [];
		}
		$this->logger->log('DEBUG', "Adding route to {$path}");
		$this->routes[$method][$route] []= $callback;
		// Longer routes must be handled first, because they are more specific
		uksort(
			$this->routes[$method],
			function(string $a, string $b): int {
				return (substr_count($b, "/") <=> substr_count($a, "/"))
					?: substr_count(basename($a), "+?)") <=> substr_count(basename($b), "+?)")
					?: strlen($b) <=> strlen($a);
			}
		);
	}

	/**
	 * Convert the route notation /foo/%s/bar into a regexp
	 */
	public function routeToRegExp(string $route): string {
		$match = preg_split("/(%[sd])/", $route, 0, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
		$newMask = array_reduce(
			$match,
			function(string $carry, string $part): string {
				if ($part === '%s') {
					return $carry . "(.+?)";
				} elseif ($part === '%d') {
					return $carry . "(\d+?)";
				} else {
					return $carry . preg_quote($part, "|");
				}
			},
			"^"
		);

		return $newMask . '$';
	}

	/**
	 * Handle new client connections
	 */
	public function clientConnected(AsyncSocket $socket): void {
		$newSocket = stream_socket_accept($socket->getSocket(), 0, $peerName);
		if ($newSocket === false) {
			return;
		}
		$this->logger->log('DEBUG', 'New client connected from ' . $peerName);
		$wrapper = $this->socket->wrap($newSocket);
		$wrapper->on(AsyncSocket::CLOSE, [$this, "handleClientDisconnect"]);
/*
		if ($this->settingManager->getBool('webserver_tls')) {
			$this->logger->log("DEBUG", "Queueing TLS handshake");
			$wrapper->writeClosureInterface(new TlsServerStart());
		}
*/
		$httpWrapper = new HttpProtocolWrapper();
		Registry::injectDependencies($httpWrapper);
		$httpWrapper->wrapAsyncSocket($wrapper);
	}

	/**
	 * Handle client disconnects / being disconnected
	 */
	public function handleClientDisconnect(AsyncSocket $scket): void {
		$this->logger->log("DEBUG", "Webserver: Client disconnected.");
	}

	/**
	 * Start listening for incoming TCP connections on the configured port
	 */
	public function listen(): bool {
		$port = $this->settingManager->getInt('webserver_port');
		$addr = $this->settingManager->getString('webserver_addr');
		$context = stream_context_create();
/*
		$tls = $this->settingManager->getBool('webserver_tls');
		if ($tls) {
			$certPath = $this->settingManager->get('webserver_certificate');
			if (empty($certPath)) {
				$certPath = $this->generateCertificate();
			}
			stream_context_set_option($context, 'ssl', 'local_cert', $certPath);
			stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
			stream_context_set_option($context, 'ssl', 'verify_peer', false);
		}
*/
		$this->serverSocket = @stream_socket_server(
			"tcp://{$addr}:{$port}",
			$errno,
			$errstr,
			STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
			$context
		);

		if ($this->serverSocket === false) {
			$error = "Could not listen on {$addr} port {$port}: {$errstr} ({$errno})";
			$this->logger->log('ERROR', $error);
			return false;
		}

		$wrapper = $this->socket->wrap($this->serverSocket);
		$wrapper->setTimeout(0);
		$wrapper->on(AsyncSocket::DATA, [$this, "clientConnected"]);
		$this->asyncSocket = $wrapper;

		$this->logger->log('INFO', "HTTP server listening on port {$port}");
		return true;
	}

	/**
	 * Shutdown the webserver
	 */
	public function shutdown(): bool {
		if (!isset($this->serverSocket) || (!is_resource($this->serverSocket) && !($this->serverSocket instanceof \Socket))) {
			return true;
		}
		if (isset($this->asyncSocket)) {
			$this->asyncSocket->destroy();
			@fclose($this->serverSocket);
		} else {
			@fclose($this->serverSocket);
		}
		$this->logger->log('INFO', "Webserver shutdown");
		return true;
	}

	/**
	 * Generate a new self-signed certificate for this bot and return the path to it
	 */
/*
	public function generateCertificate(): string {
		if (@file_exists("/tmp/server.pem")) {
			return "/tmp/server.pem";
		}
		$this->logger->log('INFO', 'Generating new SSL certificate for ' . gethostname());
		$pemfile = '/tmp/server.pem';
		$dn = [
			"countryName" => "XX",
			"localityName" => "Anarchy Online",
			"commonName" => gethostname(),
			"organizationName" => $this->chatBot->vars['name'],
		];
		if (!empty($this->chatBot->vars['my_guild'])) {
			$dn["organizationName"] = $this->chatBot->vars['my_guild'];
		}

		$privKey = openssl_pkey_new();
		$cert    = openssl_csr_new($dn, $privKey);
		$cert    = openssl_csr_sign($cert, null, $privKey, 365, null, time());

		$pem = [];
		openssl_x509_export($cert, $pem[0]);
		openssl_pkey_export($privKey, $pem[1]);
		$pem = implode("", $pem);

		// Save PEM file
		$pemfile = '/tmp/server.pem';
		file_put_contents($pemfile, $pem);
		return $pemfile;
	}
*/

	public function getHandlersForRequest(Request $request): array {
		$result = [];
		foreach ($this->routes[$request->method] as $mask => $handlers) {
			if (preg_match("|$mask|", $request->path, $parts)) {
				array_shift($parts);
				foreach ($handlers as $handler) {
					$result []= [$handler, $parts];
				}
			}
		}
		return $result;
	}

	/**
	 * @Event("http(get)")
	 * @Event("http(head)")
	 * @Event("http(post)")
	 * @Event("http(put)")
	 * @Event("http(delete)")
	 * @Event("http(patch)")
	 * @Description("Call handlers for HTTP GET requests")
	 * @DefaultStatus("1")
	 */
	public function getRequest(HttpEvent $event, HttpProtocolWrapper $server): void {
		if (!isset($event->request->authenticatedAs)) {
			$authType = $this->settingManager->getString('webserver_auth');
			if ($authType === static::AUTH_BASIC) {
				$server->httpError(new Response(
					Response::UNAUTHORIZED,
					["WWW-Authenticate" => "Basic realm=\"{$this->chatBot->vars['name']}\""],
				));
			} elseif ($authType === static::AUTH_AOAUTH) {
				$baseUrl = $this->settingManager->getString('webserver_base_url');
				if ($baseUrl === 'default') {
					$baseUrl = 'http://' . $event->request->headers['host'];
				}
				unset($event->request->query['_aoauth_token']);
				$redirectUrl = $baseUrl . $event->request->path;
				if (strlen($queryString = http_build_query($event->request->query))) {
					$redirectUrl .= "?{$queryString}";
				}
				$aoAuthUrl = rtrim($this->settingManager->getString('webserver_aoauth_url'), '/').
					'/auth';
				$server->sendResponse(new Response(
					Response::TEMPORARY_REDIRECT,
					[
						'Location' => $aoAuthUrl . '?redirect_uri='.
							urlencode($redirectUrl) . '&application_name='.
							urlencode($this->db->getMyname())
					]
				), true);
			}
			return;
		}
		if (isset($event->request->query['_aoauth_token'])) {
			$jwtUser = $this->checkJWTAuthentication($event->request->query['_aoauth_token']);
			if (isset($jwtUser)) {
				$newQuery = $event->request->query;
				unset($newQuery['_aoauth_token']);
				$redirectTo = $event->request->path;
				if (strlen($queryString = http_build_query($newQuery))) {
					$redirectTo .= "?{$queryString}";
				}
				$cookie = 'authorization=' . $event->request->query['_aoauth_token'];
				$server->sendResponse(new Response(
					Response::TEMPORARY_REDIRECT,
					[
						'Location' => $redirectTo,
						'Set-Cookie' => $cookie,
					]
				), true);
				return;
			}
		}

		$handlers = $this->getHandlersForRequest($event->request);
		foreach ($handlers as $handler) {
			$handler[0]($event->request, $server, ...$handler[1]);
		}
		if (isset($event->request->replied)) {
			return;
		}
		if (!in_array($event->request->method, [Request::HEAD, Request::GET])) {
			$server->httpError(new Response(Response::METHOD_NOT_ALLOWED));
			return;
		}
		$response = $this->serveStaticFile($event->request);
		if ($response->code !== Response::OK) {
			$server->httpError($response);
		} else {
			$server->sendResponse($response);
		}
	}

	protected function serveStaticFile(Request $request): Response {
		$path = $this->chatBot->vars["htmlfolder"] ?? "./html";
		$realFile = realpath("{$path}/{$request->path}");
		$realBaseDir = realpath("{$path}/");
		if (
			$realFile === false
			|| (
				$realFile !== $realBaseDir
				&& strncmp($realFile, $realBaseDir.DIRECTORY_SEPARATOR, strlen($realBaseDir)+1) !== 0)
		) {
			return new Response(Response::NOT_FOUND);
		}
		if (is_dir($realFile)) {
			$realFile .= DIRECTORY_SEPARATOR . "index.html";
		}
		if (!@file_exists($realFile)) {
			return new Response(Response::NOT_FOUND);
		}
		$response = new Response(
			Response::OK,
			['Content-Type' => $this->guessContentType($realFile)],
			file_get_contents($realFile)
		);
		if ($response->body === false) {
			return new Response(Response::FORBIDDEN);
		}
		$lastmodified = @filemtime($realFile);
		if ($lastmodified !== false) {
			$modifiedDate = (new DateTime())->setTimestamp($lastmodified)->format(DateTime::RFC7231);
			$response->headers['Last-Modified'] = $modifiedDate;
		}
		$response->headers['Cache-Control'] = 'private, max-age=3600';
		$response->headers['ETag'] = '"' . dechex(crc32($response->body)) . '"';
		return $response;
	}

	public function guessContentType(string $file): string {
		$info = pathinfo($file);
		switch ($info["extension"]) {
			case "html":
				return "text/html";
			case "css":
				return "text/css";
			case "js":
				return "application/javascript";
			case "json":
				return "application/json";
			case "svg":
				return "image/svg+xml";
			default:
				if (extension_loaded("fileinfo")) {
					return mime_content_type($file);
				}
				return "application/octet-stream";
		}
	}

	/** Check the signed request */
	public function checkSignature(string $signature): ?string {
		$algorithms = [
			"sha1" => OPENSSL_ALGO_SHA1,
			"sha224" => OPENSSL_ALGO_SHA224,
			"sha256" => OPENSSL_ALGO_SHA256,
			"sha384" => OPENSSL_ALGO_SHA384,
			"sha512" => OPENSSL_ALGO_SHA512,
		];
		if (!preg_match("/(?:^|,\s*)keyid\s*=\s*\"(.+?)\"/is", $signature, $matches)) {
			return null;
		}
		$keyId = $matches[1];
		if (!preg_match("/(?:^|,\s*)algorithm\s*=\s*\"(.+?)\"/is", $signature, $matches)) {
			return null;
		}
		$algorithm = $algorithms[strtolower($matches[1])]??null;
		if (!isset($algorithm)) {
			return null;
		}
		if (!preg_match("/(?:^|,\s*)sequence\s*=\s*\"?(\d+)\"?/is", $signature, $matches)) {
			return null;
		}
		$sequence = $matches[1];
		if (!preg_match("/(?:^|,\s*)signature\s*=\s*\"(.+?)\"/is", $signature, $matches)) {
			return null;
		}
		$signature = $matches[1];
		/** @var ?ApiKey */
		$key = $this->db->table(ApiController::DB_TABLE)
			->where("token", $keyId)
			->asObj(ApiKey::class)
			->first();
		if (!isset($key)) {
			return null;
		}
		if ($key->last_sequence_nr >= $sequence) {
			return null;
		}
		$decodedSig = base64_decode($signature);
		if ($decodedSig === false) {
			return null;
		}
		if (openssl_verify($sequence, $decodedSig, $key->pubkey, $algorithm) !== 1) {
			return null;
		}
		$key->last_sequence_nr = (int)$sequence;
		$this->db->update(ApiController::DB_TABLE, "id", $key);
		return $key->character;
	}

	/**
	 * Check if $user is allowed to login with password $pass
	 * @return null|string null if password is wrong, the username that was sent if correct
	 */
	public function checkAuthentication(string $user, string $password): ?string {
		$user = ucfirst(strtolower($user));
		if (!isset($this->authentications[$user])) {
			return null;
		}
		[$correctPass, $validUntil] = $this->authentications[$user];
		if ($correctPass !== $password || $validUntil < time()) {
			return null;
		}
		return $user;
	}

	/**
	 * Check if a valid user is given by a JWT
	 * @return null|string null if token is wrong, the username that was sent if correct
	 */
	public function checkJWTAuthentication(string $token): ?string {
		$aoAuthPubKey = $this->aoAuthPubKey ?? null;
		if (!isset($aoAuthPubKey)) {
			$this->logger->log('ERROR', 'No public key found to validate JWT');
			return null;
		}
		try {
			$payload = JWT::decode($token, trim($aoAuthPubKey));
		} catch (Exception $e) {
			$this->logger->log('ERROR', 'JWT: ' . $e->getMessage(), $e);
			return null;
		}
		if ($payload->exp??time() <= time()) {
			// return null;
		}
		return $payload->sub->name??null;
	}

	/**
	 * @Event("timer(10min)")
	 * @Description("Remove expired authentications")
	 * @DefaultStatus("1")
	 */
	public function clearExpiredAuthentications(): void {
		foreach ($this->authentications as $user => $data) {
			if ($data[1] < time()) {
				unset($this->authentications[$user]);
			}
		}
	}
}
