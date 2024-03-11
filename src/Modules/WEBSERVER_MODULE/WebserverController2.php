<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;
use function Safe\preg_split;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Server\{DefaultErrorHandler, HttpServer, Request, RequestHandler, Response, SocketHttpServer};
use Amp\Http\{Client, HttpStatus};
use Closure;
use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
	Registry,
    Safe,
};
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;

#[
	NCA\Instance,
]
class WebserverController2 extends ModuleInstance implements RequestHandler {
	public const AUTH_AOAUTH = "aoauth";
	public const AUTH_BASIC = "webauth";

	/** Enable webserver */
	#[NCA\Setting\Boolean(accessLevel: 'superadmin')]
	public bool $webserver2 = true;

	/** On which port does the HTTP server listen */
	#[NCA\Setting\Number(accessLevel: 'superadmin')]
	public int $webserverPort2 = 8081;

	/** Where to listen for HTTP requests */
	#[NCA\Setting\Text(
		options: ["127.0.0.1", "0.0.0.0"],
		accessLevel: 'superadmin'
	)]
	public string $webserverAddr2 = '127.0.0.1';

	/** How to authenticate against the webserver */
	#[NCA\Setting\Options(
		options: [self::AUTH_BASIC, self::AUTH_AOAUTH],
		accessLevel: "superadmin"
	)]
	public string $webserverAuth2 = self::AUTH_BASIC;

	/** Which is the base URL for the webserver? This is where aoauth redirects to */
	#[NCA\Setting\Text(
		options: ["default"],
		accessLevel: 'admin',
		help: 'webserver_base_url.txt',
	)]
	public string $webserverBaseUrl2 = 'default';

	/** If you are using aoauth to authenticate: URL of the server */
	#[NCA\Setting\Text(
		options: ["https://aoauth.org"],
		accessLevel: "superadmin"
	)]
	public string $webserverAoauthUrl2 = 'https://aoauth.org';

	/** Minimum accesslevel for the bot API and web UI */
	#[NCA\Setting\Rank]
	public string $webserverMinAL2 = "mod";

	/** @var array<string,array<string,callable[]>> */
	protected array $routes = [
		'get' => [],
		'post' => [],
		'put' => [],
		'delete' => [],
	];

	private ?HttpServer $server = null;

	private ?string $aoAuthPubKey = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Event(
		name: "connect",
		description: "Download aoauth public key"
	)]
	public function downloadPublicKey(): void {
		if ($this->webserver2) {
			$this->listen();
		}
		if ($this->webserverAuth2 !== static::AUTH_AOAUTH) {
			return;
		}
		$aoAuthKeyUrl = rtrim($this->webserverAoauthUrl2, '/') . '/key';
		$client = $this->builder->build();

		$response = $client->request(new Client\Request($aoAuthKeyUrl));
		if ($response->getStatus() !== 200) {
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

	/** Start or stop the webserver if the setting changed */
	#[NCA\SettingChangeHandler("webserver_auth2")]
	#[NCA\SettingChangeHandler("webserver_aoauth_url2")]
	public function downloadNewPublicKey(string $settingName, string $oldValue, string $newValue): void {
		$this->downloadPublicKey();
	}

	/** Start or stop the webserver if the setting changed */
	#[NCA\SettingChangeHandler("webserver2")]
	public function webserverMainSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if ($newValue === '1') {
			$this->listen();
		} else {
			$this->shutdown();
		}
	}

	/** Restart the webserver on the new port if the setting changed */
	#[NCA\SettingChangeHandler("webserver_port2")]
	#[NCA\SettingChangeHandler("webserver_addr2")]
	public function webserverSettingChanged(string $settingName, string $oldValue, string $newValue): void {
		if (!$this->server) {
			return;
		}
		$this->shutdown();
		async($this->listen(...));
	}

	public function handleRequest(Request $request): Response {
		$handlers = $this->getHandlersForRequest($request);
		return new Response(
			status: HttpStatus::OK,
			headers: ['Content-Type' => 'text/plain'],
			body: 'Hello, world!',
		);
	}

	/** Start listening for incoming TCP connections on the configured port */
	public function listen(): bool {
		$port = $this->webserverPort2;
		$addr = $this->webserverAddr2;
		$server = SocketHttpServer::createForDirectAccess($this->logger);
		$server->expose("{$addr}:{$port}");
		$server->start($this, new DefaultErrorHandler());
		$this->logger->notice("HTTP server listening on {addr}:{port}", [
			"addr" => $addr,
			"port" => $port,
		]);
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
		$this->logger->notice("Hier: {$path}");
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
}
