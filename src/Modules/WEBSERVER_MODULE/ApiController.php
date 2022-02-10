<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Closure;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandHandler,
	CommandManager,
	DB,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	ParamClass\PRemove,
	Registry,
	SettingManager,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use ReflectionAttribute;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "apiauth",
		accessLevel: "mod",
		description: "Create public/private key pairs for auth against the API",
	),
	NCA\ProvidesEvent("cmdreply")
]
class ApiController extends ModuleInstance {
	public const DB_TABLE = "api_key_<myname>";
	#[NCA\Inject]
	public WebserverController $webserverController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public WebsocketController $websocketController;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<array<string,ApiHandler>> */
	protected array $routes = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->commandManager->registerSource("api");
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'api',
			description: 'Enable REST API',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'true;false',
			intoptions: '1;0'
		);

		$this->scanApiAttributes();
	}

	/** See a list of all currently issued tokens */
	#[NCA\HandlesCommand("apiauth")]
	public function apiauthListCommand(
		CmdContext $context,
		#[NCA\Str("list")] ?string $action
	): void {
		$keys = $this->db->table(static::DB_TABLE)
			->orderBy("created")
			->asObj(ApiKey::class);
		if ($keys->isEmpty()) {
			$context->reply("There are currently no active API tokens");
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
		$context->reply($msg);
	}

	/** Create a new token for yourself */
	#[NCA\HandlesCommand("apiauth")]
	#[NCA\Help\Epilogue(
		"The <a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/REST-API#signed-requests'>full API documentation</a> ".
		"in on the Nadybot WIKI."
	)]
	public function apiauthCreateCommand(
		CmdContext $context,
		#[NCA\Str("create", "new")] string $action
	): void {
		$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
		if ($key === false) {
			$context->reply("Your PHP installation doesn't support the required cryptographic algorithms.");
			return;
		}
		$keyDetails = openssl_pkey_get_details($key);
		if ($keyDetails === false) {
			$context->reply("There was an error creating the public/private key pair");
			return;
		}
		$pubKeyPem = $keyDetails['key'];
		if (openssl_pkey_export($key, $privKeyPem) === false) {
			$context->reply(
				"There was an error extracting the private key from the generated ".
				"public/private key pair"
			);
			return;
		}
		$apiKey = new ApiKey();
		$apiKey->pubkey = $pubKeyPem;
		$apiKey->character = $context->char->name;
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
		$context->reply($msg);
	}

	/** Delete one of your tokens */
	#[NCA\HandlesCommand("apiauth")]
	public function apiauthDeleteCommand(
		CmdContext $context,
		PRemove $action,
		string $token
	): void {
		/** @var ?ApiKey */
		$key = $this->db->table(static::DB_TABLE)
			->where("token", $token)
			->asObj(ApiKey::class)
			->first();
		if (!isset($key)) {
			$context->reply("The API token <highlight>{$token}<end> was not found.");
			return;
		}
		$alDiff = $this->accessManager->compareCharacterAccessLevels($context->char->name, $key->character);
		if ($alDiff !== 1 && $context->char->name !== $key->character) {
			$context->reply(
				"Your access level must be higher than the token owner's ".
				"in order to delete their token."
			);
			return;
		}
		$this->db->table(static::DB_TABLE)->delete($key->id);
		$context->reply("API token <highlight>{$token}<end> deleted.");
	}

	/** Reset the last used sequence for one of your tokens */
	#[NCA\HandlesCommand("apiauth")]
	public function apiauthResetCommand(
		CmdContext $context,
		#[NCA\Str("reset")] string $action,
		string $token
	): void {
		/** @var ?ApiKey */
		$key = $this->db->table(static::DB_TABLE)
			->where("token", $token)
			->asObj(ApiKey::class)
			->first();
		if (!isset($key)) {
			$context->reply("The API token <highlight>{$token}<end> was not found.");
			return;
		}
		$alDiff = $this->accessManager->compareCharacterAccessLevels($context->char->name, $key->character);
		if ($alDiff !== 1 && $context->char->name !== $key->character) {
			$context->reply(
				"Your access level must be higher than the token owner's ".
				"in order to delete their token."
			);
			return;
		}
		$key->last_sequence_nr = 0;
		$this->db->update(static::DB_TABLE, "id", $key);
		$context->reply("API token <highlight>{$token}<end> reset.");
	}

	/**
	 * Scan all Instances for #[HttpGet] or #[HttpPost] attributes and register them
	 */
	public function scanApiAttributes(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$reflection = new ReflectionClass($instance);
			foreach ($reflection->getMethods() as $method) {
				$apiAttrs = $method->getAttributes(NCA\Api::class);
				if (empty($apiAttrs)) {
					continue;
				}
				$routes = [];
				foreach ($apiAttrs as $apiAttr) {
					/** @var NCA\Api */
					$apiObj = $apiAttr->newInstance();
					if (isset($apiObj->path)) {
						$routes []= $apiObj->path;
					}
				}
				$accessLevelFrom = null;
				$accessLevel = null;
				$alFromAttribs = $method->getAttributes(NCA\AccessLevelFrom::class);
				$alAttribs = $method->getAttributes(NCA\AccessLevel::class);
				if (count($alFromAttribs)) {
					/** @var NCA\AccessLevelFrom */
					$alFromObj = $alFromAttribs[0]->newInstance();
					$accessLevelFrom = $alFromObj->value;
				} elseif (count($alAttribs)) {
					/** @var NCA\AccessLevel */
					$alObj = $alAttribs[0]->newInstance();
					$accessLevel = $alObj->value;
				}
				$verbAttrs = $method->getAttributes(NCA\VERB::class, ReflectionAttribute::IS_INSTANCEOF);
				if (empty($verbAttrs)) {
					continue;
				}
				$methods = [];
				foreach ($verbAttrs as $verbAttr) {
					$methods []= strtolower(class_basename($verbAttr->getName()));
				}
				$closure = $method->getClosure($instance);
				if (isset($closure)) {
					$this->addApiRoute($routes, $methods, $closure, $accessLevelFrom, $accessLevel, $method);
				}
			}
		}
	}


	/**
	 * Add a HTTP route handler for a path
	 * @param string[] $paths
	 * @param string[] $methods
	 * @psalm-param callable(Request,HttpProtocolWrapper,mixed...) $callback
	 */
	public function addApiRoute(array $paths, array $methods, callable $callback, ?string $alf, ?string $al, ReflectionMethod $refMet): void {
		foreach ($paths as $path) {
			$handler = new ApiHandler();
			$route = $this->webserverController->routeToRegExp($path);
			$this->logger->info("Adding route to {$path}");
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
			if (!isset($handler->handler)) {
				$handler->allowedMethods = array_keys($data);
				return $handler;
			}
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
		if (!isset($handler->accessLevelFrom)) {
			return null;
		}
		$set = $this->commandManager->getPermsetMapForSource("api");
		if (!isset($set)) {
			return null;
		}
		// Check if a subcommands for this exists
		$mainCommand = explode(" ", $handler->accessLevelFrom)[0];
		if (isset($this->subcommandManager->subcommands[$mainCommand])) {
			foreach ($this->subcommandManager->subcommands[$mainCommand] as $row) {
				$perms = $row->permissions[$set->permission_set] ?? null;
				if (!isset($perms) || $row->cmd !== $handler->accessLevelFrom) {
					continue;
				}
				$files = explode(",", $row->file);
				return new CommandHandler($perms->access_level, ...$files);
			}
		}
		return $this->commandManager->commands[$set->permission_set][$handler->accessLevelFrom] ?? null;
	}


	protected function checkHasAccess(Request $request, ApiHandler $apiHandler): bool {
		$cmdHandler = $this->getCommandHandler($apiHandler);
		if ($cmdHandler === null || !isset($request->authenticatedAs)) {
			return false;
		}
		return $this->accessManager->checkAccess($request->authenticatedAs, $cmdHandler->access_level);
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
		$rqBodyAttrs = $apiHandler->reflectionMethod->getAttributes(NCA\RequestBody::class);
		if (empty($rqBodyAttrs)) {
			return true;
		}
		/** @var NCA\RequestBody */
		$reqBody = $rqBodyAttrs[0]->newInstance();
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
			if (is_object($request->decodedBody)) {
				$request->decodedBody = JsonImporter::convert($reqBody->class, $request->decodedBody);
			} elseif (is_array($request->decodedBody)) {
				foreach ($request->decodedBody as &$part) {
					$request->decodedBody = JsonImporter::convert($reqBody->class, $part);
				}
			}
		} catch (Throwable $e) {
			return false;
		}
		return true;
	}

	#[
		NCA\HttpGet("/api/%s"),
		NCA\HttpPost("/api/%s"),
		NCA\HttpPut("/api/%s"),
		NCA\HttpDelete("/api/%s"),
		NCA\HttpPatch("/api/%s"),
	]
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
			$authorized = $this->accessManager->checkAccess($request->authenticatedAs??"_", $handler->accessLevel);
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
		try {
			/** @var Response */
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
			$response->headers['Content-Length'] = "0";
			$response->setCode(Response::CREATED);
		} elseif ($response->code === Response::OK && in_array($request->method, [Request::PUT, Request::PATCH, Request::DELETE])) {
			$response->setCode(Response::NO_CONTENT);
		}
		$server->sendResponse($response);
	}

	/**
	 * Execute a command, result is sent via websocket
	 */
	#[
		NCA\Api("/execute/%s"),
		NCA\POST,
		NCA\AccessLevel("member"),
		NCA\RequestBody(class: "string", desc: "The command to execute as typed in", required: true),
		NCA\ApiResult(code: 204, desc: "operation applied successfully"),
		NCA\ApiResult(code: 404, desc: "Invalid UUID provided"),
		NCA\ApiResult(code: 422, desc: "Unparsable data received")
	]
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
		if (strlen($msg) && isset($request->authenticatedAs)) {
			$set = $this->commandManager->getPermsetMapForSource("api");
			$handler = new EventCommandReply($uuid);
			Registry::injectDependencies($handler);
			$context = new CmdContext($request->authenticatedAs);
			$context->source = "api";
			$context->setIsDM();
			$context->permissionSet = isset($set)
				? $set->permission_set
				: $this->commandManager->getPermissionSets()->firstOrFail()->name;
			$context->sendto = $handler;
			$context->message = $msg;
			$this->chatBot->getUid($context->char->name, function (?int $uid, CmdContext $context): void {
				$context->char->id = $uid;
				$this->commandManager->checkAndHandleCmd($context);
			}, $context);
		}
		return new Response(Response::NO_CONTENT);
	}
}
