<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Addendum\ReflectionAnnotatedClass;
use Closure;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\Annotations\{
	DELETE,
	GET,
	POST,
	PUT,
	PATCH,
	RequestBody,
};
use Nadybot\Core\{
	AccessManager,
	CommandHandler,
	CommandManager,
	CommandReply,
	DB,
	EventManager,
	LoggerWrapper,
	Nadybot,
	Registry,
	SettingManager,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\Migrations\ApiKey;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * @Instance
 *	@DefineCommand(
 *		command     = 'apiauth',
 *		accessLevel = 'mod',
 *		description = 'Create public/private key pairs for auth against the API',
 *		help        = 'apiauth.txt'
 *	)
 * @ProvidesEvent("cmdreply")
 */
class ApiController {
	public const DB_TABLE = "api_key_<myname>";

	public string $moduleName;

	/** @Inject */
	public WebserverController $webserverController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public WebsocketController $websocketController;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<array<string,APiHandler>> */
	protected array $routes = [];

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'api',
			'Enable REST API',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0'
		);

		$this->scanApiAnnotations();
	}

	/**
	 * @HandlesCommand("apiauth")
	 * @Matches("/^apiauth$/")
	 * @Matches("/^apiauth list$/i")
	 */
	public function apiauthListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$keys = $this->db->table(static::DB_TABLE)
			->orderBy("created")
			->asObj(ApiKey::class);
		if ($keys->isEmpty()) {
			$sendto->reply("There are currently no active API tokens");
			return;
		}
		$blocks = $keys->groupBy("character")
			->map(function (Collection $keys, string $character): string {
				return "<header2>{$character}<end>\n".
					$keys->map(function (ApiKey $key): string {
						$resetLink = $this->text->makeChatcmd(
							"reset",
							"/tell <myname> apiauth reset {$key->token}"
						);
						$delLink = $this->text->makeChatcmd(
							"remove",
							"/tell <myname> apiauth rem {$key->token}"
						);
						return "<tab><highlight>{$key->token}<end> - ".
							"sequence {$key->last_sequence_nr} [{$resetLink}], ".
							"created " . $key->created->format("Y-m-d H:i e").
							" [{$delLink}]";
					})->join("\n");
			});
		$blob = $blocks->join("\n\n");
		$msg = "All active API tokens (" . $keys->count() . ")";
		$msg = $this->text->makeBlob($msg, $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("apiauth")
	 * @Matches("/^apiauth (create|new)$/i")
	 */
	public function apiauthCreateCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
		if ($key === false) {
			$sendto->reply("Your PHP installation doesn't support the required cryptographic algorithms.");
			return;
		}
		$keyDetails = openssl_pkey_get_details($key);
		if ($keyDetails === false) {
			$sendto->reply("There was an error creating the public/private key pair");
			return;
		}
		$pubKeyPem = $keyDetails['key'];
		if (openssl_pkey_export($key, $privKeyPem) === false) {
			$sendto->reply(
				"There was an error extracting the private key from the generated ".
				"public/private key pair"
			);
			return;
		}
		$apiKey = new ApiKey();
		$apiKey->pubkey = $pubKeyPem;
		$apiKey->character = $sender;
		do {
			$apiKey->token = bin2hex(random_bytes(4));
			try {
				$apiKey->id = $this->db->insert(static::DB_TABLE, $apiKey);
			} catch (Throwable $e) {
				// Ignore and retry
			}
		} while (!isset($apiKey->id));

		$blob = "<header2>Your private key<end>\n".
			"<tab>" . implode("\n<tab>", explode("\n", trim($privKeyPem))) . "\n\n".
			"<header2>Your API token<end>\n".
			"<tab>{$apiKey->token}\n\n".
			"<header2>What to do with this?<end>\n".
			"<tab>Store both of these safely, they cannot be retrieved later.\n".
			"<tab>See ".
			$this->text->makeChatcmd(
				"the Nadybot WIKI",
				"/start https://github.com/Nadybot/Nadybot/wiki/REST-API#signed-requests"
			) . " for a documentation on how to use them.";
		$msg = $this->text->makeBlob("Your API key and token", $blob);
		$sendto->reply($msg);
		// $this->chatBot->send_tell($sender, $msg);
	}

	/**
	 * @HandlesCommand("apiauth")
	 * @Matches("/^apiauth (?:delete|del|rem|rm|erase) (.+)$/i")
	 */
	public function apiauthDeleteCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var ?ApiKey */
		$key = $this->db->table(static::DB_TABLE)
			->where("token", $args[1])
			->asObj(ApiKey::class)
			->first();
		if (!isset($key)) {
			$sendto->reply("The API token <highlight>{$args[1]}<end> was not found.");
			return;
		}
		$alDiff = $this->accessManager->compareCharacterAccessLevels($sender, $key->character);
		if ($alDiff !== 1 && $sender !== $key->character) {
			$sendto->reply(
				"Your access level must be higher than the token owner's ".
				"in order to delete their token."
			);
			return;
		}
		$this->db->table(static::DB_TABLE)->delete($key->id);
		$sendto->reply("API token <highlight>{$args[1]}<end> deleted.");
	}

	/**
	 * @HandlesCommand("apiauth")
	 * @Matches("/^apiauth (?:reset) (.+)$/i")
	 */
	public function apiauthResetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var ?ApiKey */
		$key = $this->db->table(static::DB_TABLE)
			->where("token", $args[1])
			->asObj(ApiKey::class)
			->first();
		if (!isset($key)) {
			$sendto->reply("The API token <highlight>{$args[1]}<end> was not found.");
			return;
		}
		$alDiff = $this->accessManager->compareCharacterAccessLevels($sender, $key->character);
		if ($alDiff !== 1 && $sender !== $key->character) {
			$sendto->reply(
				"Your access level must be higher than the token owner's ".
				"in order to delete their token."
			);
			return;
		}
		$key->last_sequence_nr = 0;
		$this->db->update(static::DB_TABLE, "id", $key);
		$sendto->reply("API token <highlight>{$args[1]}<end> reset.");
	}

	/**
	 * Scan all Instances for @HttpHet or @HttpPost annotations and register them
	 * @return void
	 */
	public function scanApiAnnotations(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$reflection = new ReflectionAnnotatedClass($instance);
			foreach ($reflection->getMethods() as $method) {
				/** @var \Addendum\ReflectionAnnotatedMethod $method */
				if (!$method->hasAnnotation("Api")) {
					continue;
				}
				$routes = [];
				foreach ($method->getAllAnnotations("Api") as $annotation) {
					if (isset($annotation->value)) {
						$routes []= $annotation->value;
					}
				}
				$accessLevelFrom = null;
				$accessLevel = null;
				if ($method->hasAnnotation("AccessLevelFrom")) {
					$accessLevelFrom = $method->getAnnotation("AccessLevelFrom")->value;
				} elseif ($method->hasAnnotation("AccessLevel")) {
					$accessLevel = $method->getAnnotation("AccessLevel")->value;
				}
				foreach (["GET", "POST", "PUT", "DELETE", "PATCH"] as $annoName) {
					if (!$method->hasAnnotation($annoName)) {
						continue;
					}
					$methods = [];
					foreach ($method->getAllAnnotations($annoName) as $annotation) {
						/** @var GET|POST|PUT|DELETE|PATCH $annotation */
						$methods []= strtolower($annoName);
					}
					$this->addApiRoute($routes, $methods, $method->getClosure($instance), $accessLevelFrom, $accessLevel, $method);
				}
			}
		}
	}


	/**
	 * Add a HTTP route handler for a path
	 */
	public function addApiRoute(array $paths, array $methods, callable $callback, ?string $alf, ?string $al, ReflectionMethod $refMet): void {
		foreach ($paths as $path) {
			$handler = new ApiHandler();
			$route = $this->webserverController->routeToRegExp($path);
			$this->logger->log('DEBUG', "Adding route to {$path}");
			$handler->path = $path;
			$handler->route = $route;
			$handler->allowedMethods = $methods;
			$handler->reflectionMethod = $refMet;
			$handler->handler = Closure::fromCallable($callback);
			$handler->accessLevel = $al;
			$handler->accessLevelFrom = $alf;
			foreach ($methods as $method) {
				$this->routes[$route][$method] = $handler;
			}
			// Longer routes must be handled first, because they are more specific
			uksort(
				$this->routes,
				function(string $a, string $b): int {
					return (substr_count($b, "/") <=> substr_count($a, "/"))
						?: substr_count(basename($a), "+?)") <=> substr_count(basename($b), "+?)")
						?: strlen($b) <=> strlen($a);
				}
			);
		}
	}

	public function getHandlerForRequest(Request $request, string $prefix="/api"): ?ApiHandler {
		$path = substr($request->path, strlen($prefix));
		foreach ($this->routes as $mask => $data) {
			if (!preg_match("|$mask|", $path, $parts)) {
				continue;
			}
			if (!isset($data[$request->method])) {
				$handler = new ApiHandler();
				$handler->allowedMethods = array_keys($data);
				return $handler;
			}
			$handler = clone($data[$request->method]);
			array_shift($parts);
			$ref = new ReflectionFunction($handler->handler);
			$params = $ref->getParameters();
			// Convert any parameter to int if requested by the endpoint
			for ($i = 2; $i < count($params); $i++) {
				if (!$params[$i]->hasType()) {
					continue;
				}
				$type = $params[$i]->getType();
				if ($type instanceof ReflectionNamedType) {
					if ($type->getName() === 'int') {
						$parts[$i-2] = (int)$parts[$i-2];
					}
				}
			}
			$handler->args = $parts;
			return $handler;
		}
		return null;
	}

	protected function getCommandHandler(ApiHandler $handler): ?CommandHandler {
		// Check if a subcommands for this exists
		$mainCommand = explode(" ", $handler->accessLevelFrom)[0];
		if (isset($this->subcommandManager->subcommands[$mainCommand])) {
			foreach ($this->subcommandManager->subcommands[$mainCommand] as $row) {
				if ($row->type === "msg" && ($row->cmd === $handler->accessLevelFrom || preg_match("/^{$row->cmd}$/si", $handler->accessLevelFrom))) {
					return new CommandHandler($row->file, $row->admin);
				}
			}
		}
		return $this->commandManager->commands["msg"][$handler->accessLevelFrom] ?? null;
	}


	protected function checkHasAccess(Request $request, ApiHandler $apiHandler): bool {
		$cmdHandler = $this->getCommandHandler($apiHandler);
		if ($cmdHandler === null) {
			return false;
		}
		return $this->accessManager->checkAccess($request->authenticatedAs, $cmdHandler->admin);
	}

	protected function checkBodyIsComplete(Request $request, ApiHandler $apiHandler): bool {
		if (!in_array($request->method, [$request::PUT, $request::POST])) {
			return true;
		}
		if ($request->decodedBody === null || !is_object($request->decodedBody)) {
			return true;
		}
		$refClass = new ReflectionClass($request->decodedBody);
		$refProps = $refClass->getProperties(ReflectionProperty::IS_PUBLIC);
		foreach ($refProps as $refProp) {
			if (!$refProp->isInitialized($request->decodedBody)) {
				return false;
			}
		}
		return true;
	}

	protected function checkBodyFormat(Request $request, ApiHandler $apiHandler): bool {
		if (in_array($request->method, [$request::GET, $request::HEAD, $request::DELETE])) {
			return true;
		}
		if (!$apiHandler->reflectionMethod->hasAnnotation("RequestBody")) {
			return true;
		}
		/** @var RequestBody */
		$reqBody = $apiHandler->reflectionMethod->getAnnotation("RequestBody");
		if ($request->decodedBody === null) {
			if (!$reqBody->required) {
				return true;
			}
			return false;
		}
		if (JsonImporter::matchesType($reqBody->class, $request->decodedBody)) {
			return true;
		}
		try {
			if (is_object($request->decodedBody) || is_array($request->decodedBody)) {
				$request->decodedBody = JsonImporter::convert($reqBody->class, $request->decodedBody);
			}
		} catch (Exception $e) {
			return false;
		}
		return true;
	}

	/**
	 * @HttpGet("/api/%s")
	 * @HttpPost("/api/%s")
	 * @HttpPut("/api/%s")
	 * @HttpDelete("/api/%s")
	 * @HttpPatch("/api/%s")
	 * @Description("Handle API requests")
	 */
	public function apiRequest(Request $request, HttpProtocolWrapper $server, string $path): void {
		if (!$this->settingManager->getBool('api')) {
			return;
		}
		$handler = $this->getHandlerForRequest($request);
		if ($handler === null) {
			$server->httpError(new Response(Response::NOT_FOUND));
			return;
		}
		if (!isset($handler->handler)) {
			$server->httpError(new Response(
				Response::METHOD_NOT_ALLOWED,
				['Allow' => strtoupper(join(", ", $handler->allowedMethods))]
			));
			return;
		}
		$authorized = true;
		if (isset($handler->accessLevel)) {
			$authorized = $this->accessManager->checkAccess($request->authenticatedAs, $handler->accessLevel);
		} elseif (isset($handler->accessLevelFrom)) {
			$authorized = $this->checkHasAccess($request, $handler);
		}
		if (!$authorized) {
			$server->httpError(new Response(Response::FORBIDDEN));
			return;
		}
		if ($this->checkBodyFormat($request, $handler) === false) {
			$server->httpError(new Response(Response::UNPROCESSABLE_ENTITY));
			return;
		}
		if ($this->checkBodyIsComplete($request, $handler) === false) {
			$server->httpError(new Response(Response::UNPROCESSABLE_ENTITY));
			return;
		}
		/** @var Response */
		try {
			$response = $handler->exec($request, $server);
		} catch (Throwable $e) {
			$response = null;
		}
		if (!isset($response) || !($response) instanceof Response) {
			$server->httpError(new Response(Response::INTERNAL_SERVER_ERROR));
			return;
		}
		if ($response->code >= 400) {
			$server->httpError($response);
			return;
		}
		if ($response->code >= 200 && $response->code < 300 && isset($response->body)) {
			$response->headers['Content-Type'] = 'application/json';
		} elseif ($response->code === Response::OK && $request->method === Request::POST) {
			$response->headers['Content-Length'] = 0;
			$response->setCode(Response::CREATED);
		} elseif ($response->code === Response::OK && in_array($request->method, [Request::PUT, Request::PATCH, Request::DELETE])) {
			$response->setCode(Response::NO_CONTENT);
		}
		$server->sendResponse($response);
	}

	/**
	 * Execute a command, result is sent via websocket
	 * @Api("/execute/%s")
	 * @POST
	 * @AccessLevel("member")
	 * @RequestBody(class='string', desc='The command to execute as typed in', required=true)
	 * @ApiResult(code=204, desc='operation applied successfully')
	 * @ApiResult(code=404, desc='Invalid UUID provided')
	 * @ApiResult(code=422, desc='Unparseable data received')
	 */
	public function apiExecuteCommand(Request $request, HttpProtocolWrapper $server, string $uuid): Response {
		if (!is_string($request->decodedBody)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$msg = $request->decodedBody;
		if (substr($msg, 0, 1) === $this->settingManager->getString('symbol')) {
			$msg = substr($msg, 1);
		}
		if ($this->websocketController->clientExists($uuid) === false) {
			return new Response(Response::NOT_FOUND);
		}
		if (strlen($msg)) {
			$handler = new EventCommandReply($uuid);
			Registry::injectDependencies($handler);
			$this->commandManager->process("msg", $msg, $request->authenticatedAs, $handler);
		}
		return new Response(Response::NO_CONTENT);
	}
}
