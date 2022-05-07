<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Closure;
use ReflectionClass;
use DateTime;
use Exception;
use Nadybot\Core\{
	AccessManager,
	AsyncHttp,
	Attributes as NCA,
	CmdContext,
	ConfigFile,
	DB,
	Http,
	HttpResponse,
	ModuleInstance,
	LoggerWrapper,
	Registry,
	Socket,
	Timer,
	Socket\AsyncSocket,
};
use ReflectionAttribute;
use ReflectionFunction;
use Safe\Exceptions\FilesystemException;
use Safe\Exceptions\OpensslException;
use Safe\Exceptions\StreamException;
use Safe\Exceptions\UrlException;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "webauth",
		accessLevel: "mod",
		description: "Pre-authorize Websocket connections",
	),
]
class WebserverController extends ModuleInstance {
	public const AUTH_AOAUTH = "aoauth";
	public const AUTH_BASIC = "webauth";

	/**
	 * @var ?resource
	 * @psalm-var null|resource|closed-resource
	 */
	protected $serverSocket = null;

	#[NCA\Inject]
	public Socket $socket;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Enable webserver */
	#[NCA\Setting\Boolean(accessLevel: 'superadmin')]
	public bool $webserver = true;

	/** On which port does the HTTP server listen */
	#[NCA\Setting\Number(accessLevel: 'superadmin')]
	public int $webserverPort = 8080;

	/** Where to listen for HTTP requests */
	#[NCA\Setting\Text(
		options: ["127.0.0.1", "0.0.0.0"],
		accessLevel: 'superadmin'
	)]
	public string $webserverAddr = '127.0.0.1';

	/** How to authenticate against the webserver */
	#[NCA\Setting\Options(
		options: [self::AUTH_BASIC, self::AUTH_AOAUTH],
		accessLevel: "superadmin"
	)]
	public string $webserverAuth = self::AUTH_BASIC;

	/** Which is the base URL for the webserver? This is where aoauth redirects to */
	#[NCA\Setting\Text(
		options: ["default"],
		accessLevel: 'admin',
		help: 'webserver_base_url.txt',
	)]
	public string $webserverBaseUrl = 'default';

	/** If you are using aoauth to authenticate: URL of the server */
	#[NCA\Setting\Text(
		options: ["https://aoauth.org"],
		accessLevel: "superadmin"
	)]
	public string $webserverAoauthUrl = 'https://aoauth.org';

	/** Minimum accesslevel for the bot API and web UI */
	#[NCA\Setting\Rank]
	public string $webserverMinAL = "mod";

	/** @var array<string,array<string,callable[]>> */
	protected array $routes = [
		'get' => [],
		'post' => [],
		'put' => [],
		'delete' => []
	];

	/**
	 * @var array<string,array<int|string>>
	 * @phpstan-var array<string,array{string,int}>
	 */
	protected array $authentications = [];

	protected AsyncSocket $asyncSocket;

	protected ?string $aoAuthPubKey = null;
	protected AsyncHttp $aoAuthPubKeyRequest;

	#[NCA\Event(
		name: "connect",
		description: "Download aoauth public key"
	)]
	public function downloadPublicKey(): void {
		if ($this->webserverAuth !== static::AUTH_AOAUTH) {
			return;
		}
		$aoAuthKeyUrl = rtrim($this->webserverAoauthUrl, '/') . '/key';
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
		if (isset($response->error) || $response->headers['status-code'] !== "200") {
			if (isset($response->request)) {
				$this->logger->error(
					'Error downloading aoauth pubkey from {uri}: {error}',
					[
						"uri" => $response->request->getURI(),
						"error" => ($response->error ?? $response->headers['status-code'] ?? ""),
					]
				);
			}
			return;
		}
		$this->logger->notice('New aoauth pubkey downloaded.');
		$this->aoAuthPubKey = $response->body;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->scanRouteAttributes();
		if ($this->webserver) {
			$this->listen();
		}
	}

	/**
	 * Start or stop the webserver if the setting changed
	 */
	#[NCA\SettingChangeHandler("webserver_auth")]
	#[NCA\SettingChangeHandler("webserver_aoauth_url")]
	public function downloadNewPublicKey(string $settingName, string $oldValue, string $newValue): void {
		$this->timer->callLater(0, [$this, "downloadPublicKey"]);
	}

	/**
	 * Start or stop the webserver if the setting changed
	 */
	#[NCA\SettingChangeHandler("webserver")]
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
	#[NCA\SettingChangeHandler("webserver_port")]
	#[NCA\SettingChangeHandler("webserver_addr")]
	public function webserverSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if (!$this->webserver) {
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
	 * Authenticate as an admin so you can login to the webserver
	 *
	 * You will get a token in return which you can use as a password together
	 * with your character name to login to the built-in webserver.
	 */
	#[NCA\HandlesCommand("webauth")]
	public function webauthCommand(CmdContext $context): void {
		$uuid = $this->authenticate($context->char->name, 3600);
		$msg = "You can now authenticate to the Webserver for 1h with the ".
			"credentials <highlight>{$uuid}<end>.";
		$context->reply($msg);
	}

	/**
	 * Scan all Instances for #[HttpGet] or #[HttpPost] attributes and register them
	 * @return void
	 */
	public function scanRouteAttributes(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$reflection = new ReflectionClass($instance);
			foreach ($reflection->getMethods() as $method) {
				$attrs = $method->getAttributes(NCA\HttpVerb::class, ReflectionAttribute::IS_INSTANCEOF);
				if (empty($attrs)) {
					continue;
				}
				foreach ($attrs as $attribute) {
					/** @var NCA\HttpVerb */
					$attrObj = $attribute->newInstance();
					$closure = $method->getClosure($instance);
					if (isset($closure)) {
						$this->addRoute($attrObj->type, $attrObj->path, $closure);
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
		$this->logger->info("Adding route to {$path}");
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
		$match = \Safe\preg_split("/(%[sd])/", $route, 0, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
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
		$lowSock = $socket->getSocket();
		if (!is_resource($lowSock)) {
			return;
		}
		try {
			$newSocket = \Safe\stream_socket_accept($lowSock, 0, $peerName);
		} catch (StreamException $e) {
			$this->logger->info('Error accepting client connection: {error}', [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return;
		}
		$this->logger->info('New client connected from ' . ($peerName??"Unknown location"));
		$wrapper = $this->socket->wrap($newSocket);
		$wrapper->on(AsyncSocket::CLOSE, [$this, "handleClientDisconnect"]);
		$httpWrapper = new HttpProtocolWrapper();
		Registry::injectDependencies($httpWrapper);
		$httpWrapper->wrapAsyncSocket($wrapper);
	}

	/**
	 * Handle client disconnects / being disconnected
	 */
	public function handleClientDisconnect(AsyncSocket $scket): void {
		$this->logger->info("Webserver: Client disconnected.");
	}

	/**
	 * Start listening for incoming TCP connections on the configured port
	 */
	public function listen(): bool {
		$port = $this->webserverPort;
		$addr = $this->webserverAddr;
		$context = stream_context_create();
		try {
			$serverSocket = \Safe\stream_socket_server(
				"tcp://{$addr}:{$port}",
				$errno,
				$errstr,
				STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
				$context
			);
		} catch (StreamException $e) {
			$error = "Could not listen on {addr} port {port}: {error}";
			$this->logger->error($error, [
				"addr" => $addr,
				"port" => $port,
				"error" => $e->getMessage(),
			]);
			return false;
		}
		$this->serverSocket = $serverSocket;

		$wrapper = $this->socket->wrap($this->serverSocket);
		$wrapper->setTimeout(0);
		$wrapper->on(AsyncSocket::DATA, [$this, "clientConnected"]);
		$this->asyncSocket = $wrapper;

		$this->logger->notice("HTTP server listening on port {$port}");
		return true;
	}

	/**
	 * Shutdown the webserver
	 */
	public function shutdown(): bool {
		if (!isset($this->serverSocket) || !is_resource($this->serverSocket)) {
			return true;
		}
		if (isset($this->asyncSocket)) {
			$this->asyncSocket->destroy();
			@fclose($this->serverSocket);
		} else {
			@fclose($this->serverSocket);
		}
		$this->logger->notice("Webserver shutdown");
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
		$this->logger->notice('Generating new SSL certificate for ' . gethostname());
		$pemfile = '/tmp/server.pem';
		$dn = [
			"countryName" => "XX",
			"localityName" => "Anarchy Online",
			"commonName" => gethostname(),
			"organizationName" => $this->config->name,
		];
		if (!empty($this->config->orgName)) {
			$dn["organizationName"] = $this->config->orgName;
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

	/**
	 * @return array<array<Closure|string[]>>
	 * @phpstan-return array<array{Closure,string[]}>
	 * @psalm-return array<array{Closure,string[]}>
	 */
	public function getHandlersForRequest(Request $request): array {
		$result = [];
		foreach ($this->routes[$request->method] as $mask => $handlers) {
			if (preg_match("|$mask|", $request->path, $parts)) {
				array_shift($parts);
				foreach ($handlers as $handler) {
					$result []= [Closure::fromCallable($handler), $parts];
				}
			}
		}
		return $result;
	}

	#[
		NCA\Event(
			name: [
				"http(get)",
				"http(head)",
				"http(post)",
				"http(put)",
				"http(delete)",
				"http(patch)",
			],
			description: "Call handlers for HTTP GET requests",
			defaultStatus: 1
		)
	]
	public function getRequest(HttpEvent $event, HttpProtocolWrapper $server): void {
		$handlers = $this->getHandlersForRequest($event->request);
		$needAuth = true;
		if (count($handlers) === 1) {
			$ref = new ReflectionFunction($handlers[0][0]);
			/** @psalm-suppress InvalidAttribute */
			if (count($ref->getAttributes(NCA\HttpOwnAuth::class))) {
				$needAuth = false;
			}
		}
		if ($needAuth && !isset($event->request->authenticatedAs)) {
			$authType = $this->webserverAuth;
			if ($authType === static::AUTH_BASIC) {
				$server->httpError(new Response(
					Response::UNAUTHORIZED,
					["WWW-Authenticate" => "Basic realm=\"{$this->config->name}\""],
				));
			} elseif ($authType === static::AUTH_AOAUTH) {
				$baseUrl = $this->webserverBaseUrl;
				if ($baseUrl === 'default') {
					$baseUrl = 'http://' . $event->request->headers['host'];
				}
				unset($event->request->query['_aoauth_token']);
				$redirectUrl = $baseUrl . $event->request->path;
				if (strlen($queryString = http_build_query($event->request->query))) {
					$redirectUrl .= "?{$queryString}";
				}
				$aoAuthUrl = rtrim($this->webserverAoauthUrl, '/') . '/auth';
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
				$cookie = 'authorization=' . $event->request->query['_aoauth_token'].
					"; HttpOnly";
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
		$hasMinAL = !$needAuth || $this->accessManager->checkAccess(
			$event->request->authenticatedAs ?? "Xxx",
			$this->webserverMinAL
		);
		if (!$hasMinAL) {
			$server->httpError(new Response(Response::FORBIDDEN));
			return;
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
		$path = $this->config->htmlFolder;
		try {
			$realFile = \Safe\realpath("{$path}/{$request->path}");
			$realBaseDir = \Safe\realpath("{$path}/");
		} catch (FilesystemException) {
			return new Response(Response::NOT_FOUND);
		}
		if ($realFile !== $realBaseDir
			&& strncmp($realFile, $realBaseDir.DIRECTORY_SEPARATOR, strlen($realBaseDir)+1) !== 0
		) {
			return new Response(Response::NOT_FOUND);
		}
		if (is_dir($realFile)) {
			$realFile .= DIRECTORY_SEPARATOR . "index.html";
		}
		if (!@file_exists($realFile)) {
			return new Response(Response::NOT_FOUND);
		}
		try {
			$body = \Safe\file_get_contents($realFile);
		} catch (FilesystemException) {
			$body = "";
		}
		$response = new Response(
			Response::OK,
			['Content-Type' => $this->guessContentType($realFile)],
			$body
		);
		try {
			$lastmodified = \Safe\filemtime($realFile);
			$modifiedDate = (new DateTime())->setTimestamp($lastmodified)->format(DateTime::RFC7231);
			$response->headers['Last-Modified'] = $modifiedDate;
		} catch (FilesystemException) {
		}
		$response->headers['Cache-Control'] = 'private, max-age=3600';
		$response->headers['ETag'] = '"' . dechex(crc32($body)) . '"';
		return $response;
	}

	public function guessContentType(string $file): string {
		$info = pathinfo($file);
		$extension = "";
		if (is_array($info) && isset($info["extension"])) {
			$extension = $info["extension"];
		}
		switch ($extension) {
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
					return \Safe\mime_content_type($file);
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
		try {
			$decodedSig = \Safe\base64_decode($signature);
		} catch (UrlException) {
			return null;
		}
		try {
			if (\Safe\openssl_verify($sequence, $decodedSig, $key->pubkey, $algorithm) !== 1) {
				return null;
			}
		} catch (OpensslException) {
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
			$this->logger->error('No public key found to validate JWT');
			return null;
		}
		try {
			$payload = JWT::decode($token, trim($aoAuthPubKey));
		} catch (Exception $e) {
			$this->logger->error('JWT: ' . $e->getMessage(), ["exception" => $e]);
			return null;
		}
		if ($payload->exp??time() <= time()) {
			// return null;
		}
		return $payload->sub->name??null;
	}

	#[NCA\Event(
		name: "timer(10min)",
		description: "Remove expired authentications",
		defaultStatus: 1
	)]
	public function clearExpiredAuthentications(): void {
		foreach ($this->authentications as $user => $data) {
			if ($data[1] < time()) {
				unset($this->authentications[$user]);
			}
		}
	}
}
