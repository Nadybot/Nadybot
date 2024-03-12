<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;
use function Safe\{base64_decode, json_decode, mime_content_type, openssl_verify, preg_split, realpath};

use Amp\File\{Filesystem, FilesystemException as FileFilesystemException};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Server\{DefaultErrorHandler, HttpServer, Request, RequestHandler, Response, SocketHttpServer};
use Amp\Http\{Client, HttpStatus};
use Amp\TimeoutCancellation;
use Closure;
use Exception;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	ModuleInstance,
	Registry,
	Safe,
};
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use Safe\DateTime;
use Safe\Exceptions\{FilesystemException, OpensslException, PcreException, UrlException};
use stdClass;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "webauth",
		accessLevel: "mod",
		description: "Pre-authorize Websocket connections",
	),
]
class WebserverController extends ModuleInstance implements RequestHandler {
	public const AUTH_AOAUTH = "aoauth";
	public const AUTH_BASIC = "webauth";

	public const USER = __NAMESPACE__ . "::user";
	public const BODY = __NAMESPACE__ . "::body";

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
		'delete' => [],
	];

	/**
	 * @var array<string,array<int|string>>
	 *
	 * @phpstan-var array<string,array{string,int}>
	 */
	private array $authentications = [];

	private ?HttpServer $server = null;

	private ?string $aoAuthPubKey = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Event(
		name: "connect",
		description: "Download aoauth public key"
	)]
	public function downloadPublicKey(): void {
		if ($this->webserver) {
			$this->listen();
		}
		if ($this->webserverAuth !== self::AUTH_AOAUTH) {
			return;
		}
		$aoAuthKeyUrl = rtrim($this->webserverAoauthUrl, '/') . '/key';
		$client = $this->builder->build();

		$response = $client->request(new Client\Request($aoAuthKeyUrl));
		if ($response->getStatus() !== HttpStatus::OK) {
			$this->logger->error(
				'Error downloading aoauth pubkey from {uri}: {error} ({reason})',
				[
					"uri" => $aoAuthKeyUrl,
					"error" => $response->getStatus(),
					"reason" => $response->getReason(),
				]
			);
			return;
		}
		$body = $response->getBody()->buffer();
		if ($body === '') {
			$this->logger->error('Empty aoauth pubkey received from {uri}', [
				"uri" => $aoAuthKeyUrl,
			]);
			return;
		}
		$this->logger->notice('New aoauth pubkey downloaded.');
		$this->aoAuthPubKey = $body;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->scanRouteAttributes();
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

	/** Start or stop the webserver if the setting changed */
	#[NCA\SettingChangeHandler("webserver_auth")]
	#[NCA\SettingChangeHandler("webserver_aoauth_url")]
	public function downloadNewPublicKey(string $settingName, string $oldValue, string $newValue): void {
		$this->downloadPublicKey();
	}

	/** Start or stop the webserver if the setting changed */
	#[NCA\SettingChangeHandler("webserver")]
	public function webserverMainSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if ($newValue === '1') {
			$this->listen();
		} else {
			$this->shutdown();
		}
	}

	/** Restart the webserver on the new port if the setting changed */
	#[NCA\SettingChangeHandler("webserver_port")]
	#[NCA\SettingChangeHandler("webserver_addr")]
	public function webserverSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if (!$this->server) {
			return;
		}
		$this->shutdown();
		async($this->listen(...));
	}

	public function getServer(): ?HttpServer {
		return $this->server;
	}

	/** Authenticate player $player to login to the Webserver for $duration seconds */
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

	/** Check the signed request */
	public function checkSignature(string $signature): ?string {
		$algorithms = [
			"sha1" => OPENSSL_ALGO_SHA1,
			"sha224" => OPENSSL_ALGO_SHA224,
			"sha256" => OPENSSL_ALGO_SHA256,
			"sha384" => OPENSSL_ALGO_SHA384,
			"sha512" => OPENSSL_ALGO_SHA512,
		];
		if (!count($matches = Safe::pregMatch("/(?:^|,\s*)keyid\s*=\s*\"(.+?)\"/is", $signature))) {
			return null;
		}
		$keyId = $matches[1];
		if (!count($matches = Safe::pregMatch("/(?:^|,\s*)algorithm\s*=\s*\"(.+?)\"/is", $signature))) {
			return null;
		}
		$algorithm = $algorithms[strtolower($matches[1])]??null;
		if (!isset($algorithm)) {
			return null;
		}
		if (!count($matches = Safe::pregMatch("/(?:^|,\s*)sequence\s*=\s*\"?(\d+)\"?/is", $signature))) {
			return null;
		}
		$sequence = $matches[1];
		if (!count($matches = Safe::pregMatch("/(?:^|,\s*)signature\s*=\s*\"(.+?)\"/is", $signature))) {
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
			$decodedSig = base64_decode($signature);
		} catch (UrlException) {
			return null;
		}
		try {
			if (openssl_verify($sequence, $decodedSig, $key->pubkey, $algorithm) !== 1) {
				return null;
			}
		} catch (OpensslException) {
			return null;
		}
		$key->last_sequence_nr = (int)$sequence;
		$this->db->update(ApiController::DB_TABLE, "id", $key);
		return $key->character;
	}

	public function handleRequest(Request $request): Response {
		$user = $this->getAuthenticatedUser($request);
		$request->setAttribute(self::USER, $user);
		$handlers = $this->getHandlersForRequest($request);
		$needAuth = true;
		if (count($handlers) === 1) {
			$ref = new ReflectionFunction($handlers[0][0]);

			/** @psalm-suppress InvalidAttribute */
			if (count($ref->getAttributes(NCA\HttpOwnAuth::class))) {
				$needAuth = false;
			}
		}
		if ($needAuth && !isset($user)) {
			return $this->getAuthRequiredResponse($request);
		}
		$aoAuthToken = $request->getQueryParameter('_aoauth_token');
		if (isset($aoAuthToken)) {
			$jwtUser = $this->checkJWTAuthentication($aoAuthToken);
			if (isset($jwtUser)) {
				$newRequest = clone $request;
				$newRequest->removeQueryParameter("_aoauth_token");
				$redirectTo = $newRequest->getUri()->getPath();
				if (strlen($queryString = $newRequest->getUri()->getQuery())) {
					$redirectTo .= "?{$queryString}";
				}
				$cookie = "authorization={$aoAuthToken}; HttpOnly";
				return new Response(
					status: HttpStatus::TEMPORARY_REDIRECT,
					headers: [
						'Location' => $redirectTo,
						'Set-Cookie' => $cookie,
					],
				);
			}
		}
		$error = $this->decodeRequestBody($request);
		if (isset($error)) {
			return $error;
		}

		/** @var ?string $user */
		$hasMinAL = !$needAuth || $this->accessManager->checkAccess(
			$user ?? "Xxx",
			$this->webserverMinAL
		);
		if (!$hasMinAL) {
			return new Response(
				status: HttpStatus::FORBIDDEN,
			);
		}

		foreach ($handlers as $handler) {
			$reply = $handler[0]($request, ...$handler[1]);
			if (isset($reply)) {
				return $reply;
			}
		}
		if (!in_array($request->getMethod(), ["HEAD", "GET"])) {
			return new Response(status: HttpStatus::METHOD_NOT_ALLOWED);
		}

		return $this->serveStaticFile($request);
	}

	/** Start listening for incoming TCP connections on the configured port */
	public function listen(): bool {
		$port = $this->webserverPort;
		$addr = $this->webserverAddr;
		$server = SocketHttpServer::createForDirectAccess($this->logger);
		$server->expose("{$addr}:{$port}");
		$server->start($this, new DefaultErrorHandler());
		$this->logger->notice("HTTP server listening on {addr}:{port}", [
			"addr" => $addr,
			"port" => $port,
		]);
		$this->server = $server;
		return true;
	}

	/** Shutdown the webserver */
	public function shutdown(): bool {
		if (!isset($this->server)) {
			return true;
		}
		$this->server->stop();
		$this->logger->notice("Webserver shutdown");
		return true;
	}

	/** Scan all Instances for HttpGet or HttpPost attributes and register them */
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

	/** Add a HTTP route handler for a path */
	public function addRoute(string $method, string $path, callable $callback): void {
		$route = $this->routeToRegExp($path);
		if (!isset($this->routes[$method][$route])) {
			$this->routes[$method][$route] = [];
		}
		$this->logger->info("Adding route to {path}", ["path" => $path]);
		$this->routes[$method][$route] []= $callback;
		// Longer routes must be handled first, because they are more specific
		uksort(
			$this->routes[$method],
			function (string $a, string $b): int {
				return (substr_count($b, "/") <=> substr_count($a, "/"))
					?: substr_count(basename($a), "+?)") <=> substr_count(basename($b), "+?)")
					?: strlen($b) <=> strlen($a);
			}
		);
	}

	/** Convert the route notation /foo/%s/bar into a regexp */
	public function routeToRegExp(string $route): string {
		$match = preg_split("/(%[sd])/", $route, 0, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
		$newMask = array_reduce(
			$match,
			function (string $carry, string $part): string {
				if ($part === '%s') {
					return $carry . "(.+?)";
				} elseif ($part === '%d') {
					return $carry . "(\d+?)";
				}
				return $carry . preg_quote($part, "|");
			},
			"^"
		);

		return $newMask . '$';
	}

	/**
	 * @return array<array<Closure|string[]>>
	 *
	 * @phpstan-return array<array{Closure,string[]}>
	 *
	 * @psalm-return array<array{Closure,string[]}>
	 */
	public function getHandlersForRequest(Request $request): array {
		$result = [];
		$path = $request->getUri()->getPath();
		foreach ($this->routes[strtolower($request->getMethod())] as $mask => $handlers) {
			if (count($parts = Safe::pregMatch("|{$mask}|", $path))) {
				array_shift($parts);
				foreach ($handlers as $handler) {
					$result []= [Closure::fromCallable($handler), $parts];
				}
			}
		}
		return $result;
	}

	private function decodeRequestBody(Request $request): ?Response {
		if (in_array($request->getMethod(), ["GET", "HEAD", "DELETE"], true)) {
			return null;
		}
		$body = $request->getBody()->buffer(new TimeoutCancellation(5));
		if ($body === "") {
			return null;
		}
		$contentType = $request->getHeader('content-type');
		if (!isset($contentType)) {
			return new Response(status: HttpStatus::UNSUPPORTED_MEDIA_TYPE);
		}
		if (preg_split("/;\s*/", $contentType)[0] === 'application/json') {
			try {
				$request->setAttribute(self::BODY, json_decode($body));
				return null;
			} catch (Throwable $error) {
				return new Response(
					status: HttpStatus::BAD_REQUEST,
					body: "Invalid JSON given: ".$error->getMessage()
				);
			}
		}
		if (preg_split("/;\s*/", $contentType)[0] === 'application/x-www-form-urlencoded') {
			$parts = explode("&", $body);
			$result = new stdClass();
			foreach ($parts as $part) {
				$kv = array_map("urldecode", explode("=", $part, 2));
				$result->{$kv[0]} = $kv[1] ?? null;
			}
			$request->setAttribute(self::BODY, $result);
			return null;
		}
		return new Response(status: HttpStatus::UNSUPPORTED_MEDIA_TYPE);
	}

	private function getAuthRequiredResponse(Request $request): Response {
		$authType = $this->webserverAuth;
		if ($authType === static::AUTH_BASIC) {
			return new Response(
				status: HttpStatus::UNAUTHORIZED,
				headers: ["WWW-Authenticate" => "Basic realm=\"{$this->config->main->character}\""],
			);
		} elseif ($authType === static::AUTH_AOAUTH) {
			$baseUrl = $this->webserverBaseUrl;
			if ($baseUrl === 'default') {
				$host = $request->getUri()->getHost();
				$baseUrl = "http://{$host}:{$request->getUri()->getPort()}";
			}
			$newRequest = clone $request;
			$newRequest->removeQueryParameter("_aoauth_token");
			$redirectUrl = $baseUrl . $newRequest->getUri()->getPath();
			$newRequest->getUri()->getQuery();
			if (strlen($queryString = $newRequest->getUri()->getQuery())) {
				$redirectUrl .= "?{$queryString}";
			}
			$aoAuthUrl = rtrim($this->webserverAoauthUrl, '/') . '/auth';

			return new Response(
				status: HttpStatus::TEMPORARY_REDIRECT,
				headers: [
					'Location' => $aoAuthUrl . '?redirect_uri='.
						urlencode($redirectUrl) . '&application_name='.
						urlencode($this->config->main->character),
				]
			);
		}
		throw new Exception("Invalid authentication procedure configured");
	}

	/**
	 * Check if a valid user is given by a JWT
	 *
	 * @return null|string null if token is wrong, the username that was sent if correct
	 */
	private function checkJWTAuthentication(string $token): ?string {
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
		if (!isset($payload->exp) || $payload->exp <= time()) {
			return null;
		}
		return $payload->sub->name??null;
	}

	private function getAuthenticatedUser(Request $request): ?string {
		$signature = $request->getHeader("signature");
		if (isset($signature) && strlen($signature) > 16) {
			return $this->checkSignature($signature);
		}
		$authType = $this->webserverAuth;
		if ($authType === self::AUTH_AOAUTH) {
			$token = $request->getQueryParameter('_aoauth_token');
			if (isset($token)) {
				$jwtUser = $this->checkJWTAuthentication($token);
				if (isset($jwtUser)) {
					return $jwtUser;
				}
			}
			if (!count($cookies = $request->getCookies())
				|| !isset($cookies['authorization'])) {
				return null;
			}
			return $this->checkJWTAuthentication($cookies['authorization']->getValue());
		}
		$authorization = $request->getHeader("authorization");
		if (!isset($authorization)) {
			return null;
		}
		try {
			$parts = preg_split("/\s+/", $authorization);
			if (count($parts) !== 2 || strtolower($parts[0]) !== 'basic') {
				return null;
			}
			$userPassString = base64_decode($parts[1]);
		} catch (PcreException | UrlException) {
			return null;
		}
		$userPass = explode(":", $userPassString, 2);
		if (count($userPass) !== 2) {
			return null;
		}
		return $this->checkAuthentication($userPass[0], $userPass[1]);
	}

	/**
	 * Check if $user is allowed to login with password $pass
	 *
	 * @return null|string null if password is wrong, the username that was sent if correct
	 */
	private function checkAuthentication(string $user, string $password): ?string {
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

	private function guessContentType(string $fileName): string {
		$info = pathinfo($fileName);
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
					return mime_content_type($fileName);
				}
				return "application/octet-stream";
		}
	}

	private function serveStaticFile(Request $request): Response {
		$path = $this->config->paths->html;
		try {
			$realFile = realpath("{$path}/{$request->getUri()->getPath()}");
			$realBaseDir = realpath("{$path}/");
		} catch (FilesystemException) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		if ($realFile !== $realBaseDir
			&& strncmp($realFile, $realBaseDir.DIRECTORY_SEPARATOR, strlen($realBaseDir)+1) !== 0
		) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		if ($this->fs->isDirectory($realFile)) {
			$realFile .= DIRECTORY_SEPARATOR . "index.html";
		}
		if (!$this->fs->exists($realFile)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		try {
			$body = $this->fs->read($realFile);
		} catch (FileFilesystemException) {
			$body = "";
		}
		$response = new Response(
			status: HttpStatus::OK,
			headers: ['Content-Type' => $this->guessContentType($realFile)],
			body: $body,
		);
		try {
			$lastmodified = $this->fs->getModificationTime($realFile);
			$modifiedDate = (new DateTime())->setTimestamp($lastmodified)->format(DateTime::RFC7231);
			$response->setHeader('Last-Modified', $modifiedDate);
		} catch (Exception) {
		}
		$response->setHeader('Cache-Control', 'private, max-age=3600');
		$response->setHeader('ETag', '"' . dechex(crc32($body)) . '"');
		return $response;
	}
}
