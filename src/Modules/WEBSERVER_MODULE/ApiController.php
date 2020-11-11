<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Addendum\ReflectionAnnotatedClass;
use Closure;
use Exception;
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
	LoggerWrapper,
	Registry,
	SettingManager,
};
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @Instance
 */
class ApiController {
	public string $moduleName;

	/** @Inject */
	public WebserverController $webserverController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public AccessManager $accessManager;

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
			'0',
			'true;false',
			'1;0'
		);

		$this->scanApiAnnotations();
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
		if (isset($this->subcommandManager->subcommands[$handler->accessLevelFrom])) {
			foreach ($this->subcommandManager->subcommands[$handler->accessLevelFrom] as $row) {
				return new CommandHandler($row->file, $row->admin);
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
		$response = $handler->exec($request, $server);
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
}
