<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Closure;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandHandler,
	CommandManager,
	DB,
	ModuleInstance,
	Modules\SYSTEM\SystemController,
	Nadybot,
	ParamClass\PRemove,
	Registry,
	Safe,
	SubcommandManager,
	Text,
};
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "apiauth",
		accessLevel: "mod",
		description: "Create public/private key pairs for auth against the API",
	),
	NCA\ProvidesEvent(CommandReplyEvent::class)
]
class ApiController extends ModuleInstance {
	public const DB_TABLE = "api_key_<myname>";

	/** Enable REST API */
	#[NCA\Setting\Boolean]
	public bool $api = true;

	/** @var array<array<string,ApiHandler>> */
	protected array $routes = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private WebserverController $webserverController;

	#[NCA\Inject]
	private SystemController $systemController;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private WebsocketController $websocketController;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandManager->registerSource("api");

		$this->scanApiAttributes();
	}

	/** See a list of all currently issued tokens */
	#[NCA\HandlesCommand("apiauth")]
	public function apiauthListCommand(
		CmdContext $context,
		#[NCA\Str("list")]
		?string $action
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
		#[NCA\Str("create", "new")]
		string $action
	): void {
		// @phpstan-ignore-next-line
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
		// @phpstan-ignore-next-line
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
		#[NCA\Str("reset")]
		string $action,
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

	/** Scan all Instances for #[HttpGet] or #[HttpPost] attributes and register them */
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
					$alFromObj = $alFromAttribs[0]->newInstance();
					$accessLevelFrom = $alFromObj->value;
				} elseif (count($alAttribs)) {
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
	 *
	 * @param string[]                           $paths
	 * @param string[]                           $methods
	 * @param Closure(Request,mixed...):Response $callback
	 */
	public function addApiRoute(array $paths, array $methods, Closure $callback, ?string $alf, ?string $al, ReflectionMethod $refMet): void {
		foreach ($paths as $path) {
			$handler = new ApiHandler();
			$route = $this->webserverController->routeToRegExp($path);
			$this->logger->info("Adding route to {path}", ["path" => $path]);
			$handler->path = $path;
			$handler->route = $route;
			$handler->allowedMethods = $methods;
			$handler->reflectionMethod = $refMet;
			$handler->handler = $callback;
			$handler->accessLevel = $al;
			$handler->accessLevelFrom = $alf;
			foreach ($methods as $method) {
				$this->routes[$route][$method] = $handler;
			}
			// Longer routes must be handled first, because they are more specific
			uksort(
				$this->routes,
				function (string $a, string $b): int {
					return (substr_count($b, "/") <=> substr_count($a, "/"))
						?: substr_count(basename($a), "+?)") <=> substr_count(basename($b), "+?)")
						?: strlen($b) <=> strlen($a);
				}
			);
		}
	}

	public function getHandlerForRequest(Request $request, string $prefix="/api"): ?ApiHandler {
		$method = strtolower($request->getMethod());
		$path = substr($request->getUri()->getPath(), strlen($prefix));
		foreach ($this->routes as $mask => $data) {
			if (!count($parts = Safe::pregMatch("|{$mask}|", $path))) {
				continue;
			}
			if (!isset($data[$method])) {
				$handler = new ApiHandler();
				$handler->allowedMethods = array_keys($data);
				return $handler;
			}
			$handler = clone $data[$method];
			if (!isset($handler->handler)) {
				$handler->allowedMethods = array_keys($data);
				return $handler;
			}
			array_shift($parts);
			$ref = new ReflectionFunction($handler->handler);
			$params = $ref->getParameters();
			// Convert any parameter to int if requested by the endpoint
			for ($i = 1; $i < count($params); $i++) {
				if (!$params[$i]->hasType()) {
					continue;
				}
				$type = $params[$i]->getType();
				if ($type instanceof ReflectionNamedType) {
					if ($type->getName() === 'int') {
						$parts[$i-1] = (int)$parts[$i-1];
					}
				}
			}
			$handler->args = $parts;
			return $handler;
		}
		return null;
	}

	#[
		NCA\HttpGet("/api/%s"),
		NCA\HttpPost("/api/%s"),
		NCA\HttpPut("/api/%s"),
		NCA\HttpDelete("/api/%s"),
		NCA\HttpPatch("/api/%s"),
	]
	public function apiRequest(Request $request, string $path): ?Response {
		if (!$this->api) {
			return null;
		}
		$handler = $this->getHandlerForRequest($request);
		if ($handler === null) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		if (!isset($handler->handler)) {
			return new Response(
				status: HttpStatus::METHOD_NOT_ALLOWED,
				headers: ['Allow' => strtoupper(join(", ", $handler->allowedMethods))]
			);
		}
		$authorized = true;

		/** @var ?string */
		$user = $request->getAttribute(WebserverController::USER);
		if (isset($handler->accessLevel)) {
			$authorized = $this->accessManager->checkAccess($user??"_", $handler->accessLevel);
		} elseif (isset($handler->accessLevelFrom)) {
			$authorized = $this->checkHasAccess($user, $request, $handler);
		}
		if (!$authorized) {
			return new Response(status: HttpStatus::FORBIDDEN);
		}
		if ($this->checkBodyFormat($request, $handler) === false) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		if ($this->checkBodyIsComplete($request, $handler) === false) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		try {
			$response = $handler->exec($request);
		} catch (Throwable) {
			$response = null;
		}

		if (!isset($response)) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		if ($response->getStatus() >= 400) {
			return $response;
		}
		$bodyLength = $response->getHeader('content-length');
		$status = $response->getStatus();
		if ($status >= 200 && $status < 300 && isset($bodyLength) && $bodyLength > 0) {
			$response->setHeader('Content-Type', 'application/json');
		} elseif ($status === HttpStatus::OK && $request->getMethod() === 'POST') {
			$response->setBody("");
			$response->setStatus(HttpStatus::CREATED);
		} elseif ($status === HttpStatus::OK && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'])) {
			$response->setStatus(HttpStatus::NO_CONTENT);
		}
		return $response;
	}

	/** Execute a command, result is sent via websocket */
	#[
		NCA\Api("/execute/%s"),
		NCA\POST,
		NCA\AccessLevel("member"),
		NCA\RequestBody(class: "string", desc: "The command to execute as typed in", required: true),
		NCA\ApiResult(code: 204, desc: "operation applied successfully"),
		NCA\ApiResult(code: 404, desc: "Invalid UUID provided"),
		NCA\ApiResult(code: 422, desc: "Unparsable data received")
	]
	public function apiExecuteCommand(Request $request, string $uuid): Response {
		$msg = $request->getAttribute(WebserverController::BODY);

		/** @var ?string */
		$user = $request->getAttribute(WebserverController::USER);
		if (substr($msg, 0, 1) === $this->systemController->symbol) {
			$msg = substr($msg, 1);
		}
		if ($this->websocketController->clientExists($uuid) === false) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		if (isset($msg) && isset($user)) {
			$set = $this->commandManager->getPermsetMapForSource("api");
			$handler = new EventCommandReply($uuid);
			Registry::injectDependencies($handler);
			$context = new CmdContext($user);
			$context->source = "api";
			$context->setIsDM();
			$context->permissionSet = isset($set)
				? $set->permission_set
				: $this->commandManager->getPermissionSets()->firstOrFail()->name;
			$context->sendto = $handler;
			$context->message = $msg;
			async(function () use ($context): void {
				$uid = $this->chatBot->getUid($context->char->name);
				$context->char->id = $uid;
				$this->commandManager->checkAndHandleCmd($context);
			});
		}
		return new Response(status: HttpStatus::NO_CONTENT);
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

	protected function checkHasAccess(?string $user, Request $request, ApiHandler $apiHandler): bool {
		$cmdHandler = $this->getCommandHandler($apiHandler);
		if ($cmdHandler === null || !isset($user)) {
			return false;
		}
		return $this->accessManager->checkAccess($user, $cmdHandler->access_level);
	}

	protected function checkBodyIsComplete(Request $request, ApiHandler $apiHandler): bool {
		if (!in_array($request->getMethod(), ['PUT', 'POST'])) {
			return true;
		}
		$body = $request->getAttribute(WebserverController::BODY);
		if ($body === null || !is_object($body)) {
			return true;
		}
		return true;
	}

	protected function checkBodyFormat(Request $request, ApiHandler $apiHandler): bool {
		if (in_array($request->getMethod(), ['GET', 'HEAD', 'DELETE'])) {
			return true;
		}
		$rqBodyAttrs = $apiHandler->reflectionMethod->getAttributes(NCA\RequestBody::class);
		if (empty($rqBodyAttrs)) {
			return true;
		}

		$body = $request->getAttribute(WebserverController::BODY);

		$reqBody = $rqBodyAttrs[0]->newInstance();
		if ($body === null) {
			if (!$reqBody->required) {
				return true;
			}
			return false;
		}
		if (JsonImporter::matchesType($reqBody->class, $body)) {
			return true;
		}
		try {
			if (is_object($body)) {
				$request->setAttribute(WebserverController::BODY, JsonImporter::convert($reqBody->class, $body));
			} elseif (is_array($body)) {
				$newBody = [];
				foreach ($body as $key => $part) {
					$newBody[$key] = JsonImporter::convert($reqBody->class, $part);
				}
				$request->setAttribute(WebserverController::BODY, $newBody);
			}
		} catch (Throwable $e) {
			return false;
		}
		return true;
	}
}
