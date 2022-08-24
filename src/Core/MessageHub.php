<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\call;
use function Amp\Promise\rethrow;

use Amp\Promise;
use Exception;
use Generator;
use Illuminate\Support\Collection;
use JsonException;
use Monolog\Logger;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\Route,
	DBSchema\RouteHopColor,
	DBSchema\RouteHopFormat,
	Routing\RoutableEvent,
	Routing\Source,
};
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

#[NCA\Instance]
class MessageHub {
	public const EVENT_NOT_ROUTED = 0;
	public const EVENT_DISCARDED = 1;
	public const EVENT_DELIVERED = 2;
	public const DB_TABLE_ROUTES = "route_<myname>";
	public const DB_TABLE_COLORS = "route_hop_color_<myname>";
	public const DB_TABLE_TEXT_COLORS = "route_text_color_<myname>";
	public const DB_TABLE_ROUTE_MODIFIER = "route_modifier_<myname>";
	public const DB_TABLE_ROUTE_MODIFIER_ARGUMENT = "route_modifier_argument_<myname>";

	/** @var array<string,ClassSpec> */
	public array $modifiers = [];

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public BuddylistManager $buddyListManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var Collection<RouteHopColor> */
	public static Collection $colors;

	public bool $routingLoaded = false;

	/** @var RoutableEvent[] */
	public array $eventQueue = [];

	/** @var array<string,MessageReceiver> */
	protected array $receivers = [];

	/** @var array<string,MessageEmitter> */
	protected array $emitters = [];

	/** @var array<string,array<string,MessageRoute[]>> */
	protected array $routes = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->parseMessageEmitters();
		$modifierFiles = \Safe\glob(__DIR__ . "/EventModifier/*.php");
		foreach ($modifierFiles as $file) {
			require_once $file;
			$className = basename($file, '.php');
			$fullClass = __NAMESPACE__ . "\\EventModifier\\{$className}";
			if (!class_exists($fullClass)) {
				continue;
			}
			$spec = $this->util->getClassSpecFromClass($fullClass, NCA\EventModifier::class);
			if (isset($spec)) {
				$this->registerEventModifier($spec);
			}
		}
		$this->loadTagFormat();
		$this->loadTagColor();
	}

	public function parseMessageEmitters(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$refClass = new ReflectionClass($instance);
			foreach ($refClass->getAttributes(NCA\EmitsMessages::class) as $attr) {
				$obj = $attr->newInstance();
				$this->registerMessageEmitter($obj);
			}
		}
	}

	public function loadTagFormat(): void {
		$query = $this->db->table(Source::DB_TABLE);
		Source::$format = $query
			->orderByDesc($query->colFunc("LENGTH", "hop"))
			->asObj(RouteHopFormat::class);
	}

	public function loadTagColor(): void {
		$query = $this->db->table(static::DB_TABLE_COLORS);
		static::$colors = $query
			->orderByDesc($query->colFunc("LENGTH", "hop"))
			->orderByDesc($query->colFunc("LENGTH", "where"))
			->orderByDesc($query->colFunc("LENGTH", "via"))
			->asObj(RouteHopColor::class);
	}

	/** Register an event modifier for public use */
	public function registerEventModifier(ClassSpec $spec): void {
		$name = strtolower($spec->name);
		if (isset($this->modifiers[$name])) {
			$printArgs = [];
			foreach ($this->modifiers[$name]->params as $param) {
				if (!$param->required) {
					$printArgs []= "[{$param->type} {$param->name}]";
				} else {
					$printArgs []= "{$param->type} {$param->name}";
				}
			}
			throw new Exception(
				"There is already an EventModifier {$name}(".
				join(", ", $printArgs).
				")"
			);
		}
		$this->modifiers[$name] = $spec;
	}

	/**
	 * Get a fully configured event modifier or null if not possible
	 *
	 * @param string                        $name   Name of the modifier
	 * @param array<string,string|string[]> $params The parameters of the modifier
	 */
	public function getEventModifier(string $name, array $params): ?EventModifier {
		$name = strtolower($name);
		$spec = $this->modifiers[$name] ?? null;
		if (!isset($spec)) {
			return null;
		}
		$arguments = [];
		$paramPos = 0;
		foreach ($spec->params as $parameter) {
			$value = $params[$parameter->name] ?? null;
			if (isset($value)) {
				switch ($parameter->type) {
					case $parameter::TYPE_BOOL:
						if (!is_string($value) || !in_array($value, ["true", "false"])) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'" . join(", ", (array)$value) . "'<end> given."
							);
						}
						$arguments []= $value === "true";
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_INT:
						if (!is_string($value) || !preg_match("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'" . join(", ", (array)$value) . "'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_STRING_ARRAY:
						$value = array_map(fn ($x) => (string)$x, (array)$value);
						$arguments []= $value;
						unset($params[$parameter->name]);
						break;
					default:
						foreach ((array)$value as $v) {
							$arguments []= (string)$v;
						}
						unset($params[$parameter->name]);
				}
			} elseif ($parameter->required) {
				throw new Exception(
					"Missing required argument <highlight>{$parameter->name}<end> ".
					"to <highlight>{$name}<end>."
				);
			} else {
				try {
					$ref = new ReflectionMethod($spec->class, "__construct");
				} catch (ReflectionException $e) {
					continue;
				}
				$conParams = $ref->getParameters();
				if (!isset($conParams[$paramPos])) {
					continue;
				}
				if ($conParams[$paramPos]->isOptional()) {
					$arguments []= $conParams[$paramPos]->getDefaultValue();
				}
			}
			$paramPos++;
		}
		if (!empty($params)) {
			throw new Exception(
				"Unknown parameter" . (count($params) > 1 ? "s" : "").
				" <highlight>".
				(new Collection(array_keys($params)))
					->join("<end>, <highlight>", "<end> and <highlight>").
				"<end> to <highlight>{$name}<end>."
			);
		}
		$class = $spec->class;
		if (!is_subclass_of($class, EventModifier::class)) {
			throw new Exception("{$class} is registered as an EventModifier, but not a subclass of it.");
		}
		try {
			$obj = new $class(...$arguments);
			Registry::injectDependencies($obj);
			return $obj;
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} modifier: " . $e->getMessage());
		}
	}

	/** Register an object for handling messages for a channel */
	public function registerMessageReceiver(MessageReceiver $messageReceiver): self {
		$channel = $messageReceiver->getChannelName();
		$this->receivers[strtolower($channel)] = $messageReceiver;
		$this->logger->info("Registered new event receiver for {$channel}");
		return $this;
	}

	/** Register an object as an emitter for a channel */
	public function registerMessageEmitter(MessageEmitter $messageEmitter): self {
		$channel = $messageEmitter->getChannelName();
		$this->emitters[strtolower($channel)] = $messageEmitter;
		$this->logger->info("Registered new event emitter for {$channel}");
		return $this;
	}

	/** Unregister an object for handling messages for a channel */
	public function unregisterMessageReceiver(string $channel): self {
		unset($this->receivers[strtolower($channel)]);
		$this->logger->info("Removed event receiver for {$channel}");
		return $this;
	}

	/** Unregister an object as an emitter for a channel */
	public function unregisterMessageEmitter(string $channel): self {
		unset($this->emitters[strtolower($channel)]);
		$this->logger->info("Removed event emitter for {$channel}");
		return $this;
	}

	/** Determine the most specific receiver for a channel */
	public function getReceiver(string $channel): ?MessageReceiver {
		$channel = strtolower($channel);
		if (isset($this->receivers[$channel])) {
			return $this->receivers[$channel];
		}
		foreach ($this->receivers as $receiverChannel => $receiver) {
			if (fnmatch($receiverChannel, $channel, FNM_CASEFOLD)) {
				return $receiver;
			}
		}
		return null;
	}

	/**
	 * Get a list of all message receivers
	 *
	 * @return array<string,MessageReceiver>
	 */
	public function getReceivers(): array {
		return $this->receivers;
	}

	/** Check if there is a route defined for a MessageSender */
	public function hasRouteFor(string $sender): bool {
		$sender = strtolower($sender);
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (fnmatch($source, $sender, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	/** Check if there is a route defined for a MessageSender to a receiver */
	public function hasRouteFromTo(string $sender, string $destination): bool {
		$sender = strtolower($sender);
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (!fnmatch($source, $sender, FNM_CASEFOLD)) {
				continue;
			}
			foreach ($dest as $destName => $routes) {
				if (empty($routes)) {
					continue;
				}
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					continue;
				}
				if (fnmatch($receiver->getChannelName(), $destination, FNM_CASEFOLD)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get a list of all message emitters
	 *
	 * @return array<string,MessageEmitter>
	 */
	public function getEmitters(): array {
		return $this->emitters;
	}

	/** Submit an event to be routed according to the configured connections */
	public function handle(RoutableEvent $event): int {
		$this->logger->info("Received event to route");
		$path = $event->getPath();
		if (empty($path)) {
			$this->logger->info("Discarding event without path");
			return static::EVENT_NOT_ROUTED;
		}
		$type = strtolower("{$path[0]->type}({$path[0]->name})");
		$eventLogLevel = null;
		if ($path[0]->type === Source::LOG) {
			/**
			 * @phpstan-ignore-next-line
			 * @psalm-suppress ArgumentTypeCoercion
			 */
			$eventLogLevel = Logger::toMonologLevel($path[0]->name);
		}
		try {
			$this->logger->info(
				"Trying to route {$type} - ".
				\Safe\json_encode($event, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)
			);
		} catch (JsonException $e) {
			// Ignore
		}
		if ($this->routingLoaded === false) {
			$this->eventQueue []= $event;
			return static::EVENT_NOT_ROUTED;
		}
		if (($queued = array_pop($this->eventQueue)) !== null) {
			$this->handle($queued);
		}
		$returnStatus = static::EVENT_NOT_ROUTED;
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (isset($eventLogLevel)
				&& preg_match("/^" . preg_quote(Source::LOG, "/") . "\(([a-z]+)\)$/i", $source, $matches)
			) {
				try {
					/** @psalm-suppress ArgumentTypeCoercion */
					$srcLevel = Logger::toMonologLevel($matches[1]);
					if ($eventLogLevel < $srcLevel) {
						continue;
					}
				} catch (Exception $e) {
					continue;
				}
			} elseif (!fnmatch($source, $type, FNM_CASEFOLD)) {
				continue;
			}
			foreach ($dest as $destName => $routes) {
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					$this->logger->info("No receiver registered for {$destName}");
					continue;
				}
				foreach ($routes as $route) {
					if ($route->isDisabled()) {
						$this->logger->info("Routing to {$destName} temporarily disabled");
						$returnStatus = max($returnStatus, static::EVENT_NOT_ROUTED);
						continue;
					}
					$modifiedEvent = $route->modifyEvent($event);
					if (!isset($modifiedEvent)) {
						$this->logger->info("Event filtered away for {$destName}");
						$returnStatus = max($returnStatus, static::EVENT_NOT_ROUTED);
						continue;
					}
					$this->logger->info("Event routed to {$destName}");
					$destination = $route->getDest();
					if (preg_match("/\((.+)\)$/", $destination, $matches)) {
						$destination = $matches[1];
					}
					$receiver->receive($modifiedEvent, $destination);
					if (!$modifiedEvent->routeSilently) {
						$returnStatus = static::EVENT_DELIVERED;
					}
				}
			}
		}
		return $returnStatus;
	}

	/** Get the text to prepend to a message to denote its source path */
	public function renderPath(RoutableEvent $event, string $where, bool $withColor=true, bool $withUserLink=true): string {
		$hops = [];
		$lastHop = null;
		foreach ($event->getPath() as $hop) {
			$renderedHop = $this->renderSource($hop, $event, $where, $withColor);
			if (isset($renderedHop)) {
				$hops []= $renderedHop;
			}
			$lastHop = $hop;
		}
		$charLink = "";
		$hopText = "";
		$char = $event->getCharacter();
		// Render "[Name]" instead of "[Name] Name: "
		$isTell = (isset($lastHop) && $lastHop->type === Source::TELL);
		if (isset($char) && !$isTell) {
			$charLink = $char->name . ": ";
			$aoSources = [Source::ORG, Source::PRIV, Source::PUB, Source::TELL];
			if (in_array($lastHop->type??null, $aoSources) && $withUserLink) {
				$charLink = $this->text->makeUserlink($char->name) . ": ";
			}
		}
		if (!empty($hops)) {
			$hopText = join(" ", $hops) . " ";
		}
		return $hopText.$charLink;
	}

	public function renderSource(Source $source, RoutableEvent $event, string $where, bool $withColor): ?string {
		$lastHop = null;
		$hops = $event->getPath();
		$hopPos = array_search($source, $hops, true);
		if ($hopPos === false) {
			return null;
		}
		$lastHop = ($hopPos === 0) ? null : $hops[(int)$hopPos-1];
		$name = $source->render($lastHop);
		if (!isset($name)) {
			return null;
		}
		if (!$withColor) {
			return "[{$name}]";
		}
		$color = $this->getHopColor($hops, $where, $source, "tag_color");
		if (!isset($color)) {
			return "[{$name}]";
		}
		return "<font color=#{$color->tag_color}>[{$name}]<end>";
	}

	public function getCharacter(string $dest): ?string {
		$regExp = "/" . preg_quote(Source::TELL, "/") . "\((.+)\)$/";
		if (!preg_match($regExp, $dest, $matches)) {
			return null;
		}
		return $matches[1];
	}

	/** Add a route to the routing table, either adding or replacing */
	public function addRoute(MessageRoute $route): void {
		$source = $route->getSource();
		$dest = $route->getDest();

		$this->routes[$source] ??= [];
		$this->routes[$source][$dest] ??= [];
		$this->routes[$source][$dest] []= $route;
		$char = $this->getCharacter($dest);
		if (isset($char)) {
			rethrow($this->buddyListManager->addAsync($char, "msg_hub"));
		}
		if (!$route->getTwoWay()) {
			return;
		}
		$this->routes[$dest] ??= [];
		$this->routes[$dest][$source] ??= [];
		$this->routes[$dest][$source] []= $route;
		$char = $this->getCharacter($source);
		if (isset($char)) {
			rethrow($this->buddyListManager->addAsync($char, "msg_hub"));
		}
	}

	/** @return MessageRoute[] */
	public function getRoutes(): array {
		$allRoutes = [];
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $routes) {
				foreach ($routes as $route) {
					$allRoutes [$route->getID()] = $route;
				}
			}
		}
		return array_values($allRoutes);
	}

	/**
	 * Get a list of commands to re-create all routes
	 *
	 * @return string[]
	 */
	public function getRouteDump(bool $useForce=false): array {
		$routes = $this->getRoutes();
		$cmd = $useForce ? "addforce" : "add";
		return array_map(function (MessageRoute $route) use ($cmd): string {
			$routeCode = $route->getSource();
			if ($route->getTwoWay()) {
				$routeCode .= " <-> ";
			} else {
				$routeCode .= " -> ";
			}
			$routeCode .= $route->getDest();
			$mods = $route->renderModifiers();
			if (count($mods)) {
				$routeCode .= " " . join(" ", $mods);
			}
			return "!route {$cmd} {$routeCode}";
		}, $routes);
	}

	public function deleteRouteID(int $id): ?MessageRoute {
		$result = null;
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $routes) {
				for ($i = 0; $i < count($routes); $i++) {
					$route = $routes[0];
					if ($route->getID() !== $id) {
						continue;
					}
					$result = $route;
					unset($this->routes[$source][$dest][$i]);
					$char = $this->getCharacter($dest);
					if (isset($char)) {
						$this->buddyListManager->remove($char, "msg_hub");
					}
					if ($result->getTwoWay()) {
						$char = $this->getCharacter($source);
						if (isset($char)) {
							$this->buddyListManager->remove($char, "msg_hub");
						}
					}
				}
				$this->routes[$source][$dest] = array_values(
					$this->routes[$source][$dest]
				);
				if (empty($this->routes[$source][$dest])) {
					unset($this->routes[$source][$dest]);
				}
			}
		}
		return $result;
	}

	/**
	 * Remove all routes from the routing table and return how many were removed
	 *
	 * @return Promise<int>
	 */
	public function deleteAllRoutes(): Promise {
		return call(function (): Generator {
			$routes = $this->getRoutes();
			yield $this->db->awaitBeginTransaction();
			try {
				$this->db->table(MessageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT)->truncate();
				$this->db->table(MessageHub::DB_TABLE_ROUTE_MODIFIER)->truncate();
				$this->db->table(MessageHub::DB_TABLE_ROUTES)->truncate();
			} catch (Exception $e) {
				$this->db->rollback();
				throw $e;
			}
			$this->db->commit();
			$this->routes = [];
			return count($routes);
		});
	}

	/**
	 * Convert a DB-representation of a route to the real deal
	 *
	 * @param Route $route The DB representation
	 *
	 * @return MessageRoute The actual message route
	 *
	 * @throws Exception whenever this is impossible
	 */
	public function createMessageRoute(Route $route): MessageRoute {
		$msgRoute = new MessageRoute($route);
		Registry::injectDependencies($msgRoute);
		foreach ($route->modifiers as $modifier) {
			$modObj = $this->getEventModifier(
				$modifier->modifier,
				$modifier->getKVArguments()
			);
			if (!isset($modObj)) {
				throw new Exception("There is no modifier <highlight>{$modifier->modifier}<end>.");
			}
			$msgRoute->addModifier($modObj);
		}
		return $msgRoute;
	}

	/** @param Source[] $path */
	public function getHopColor(array $path, string $where, Source $source, string $color): ?RouteHopColor {
		$colorDefs = static::$colors;
		if (isset($source->name)) {
			$fullDefs = $colorDefs->filter(function (RouteHopColor $color): bool {
				return strpos($color->hop, "(") !== false;
			});
			foreach ($fullDefs as $colorDef) {
				if (!fnmatch($colorDef->hop, "{$source->type}({$source->name})", FNM_CASEFOLD)) {
					continue;
				}
				$colorWhere = $colorDef->where??'*';
				if (!fnmatch($colorWhere, $where, FNM_CASEFOLD)
					&& !fnmatch($colorWhere.'(*)', $where, FNM_CASEFOLD)) {
					continue;
				}
				if (isset($colorDef->via) && !$this->isSentVia($colorDef->via, $path)) {
					continue;
				}
				if (isset($colorDef->{$color})) {
					return $colorDef;
				}
			}
		}
		foreach ($colorDefs as $colorDef) {
			$colorWhere = $colorDef->where??'*';
			if (!fnmatch($colorWhere, $where, FNM_CASEFOLD)
				&& !fnmatch($colorWhere.'(*)', $where, FNM_CASEFOLD)) {
				continue;
			}
			if (isset($colorDef->via) && !$this->isSentVia($colorDef->via, $path)) {
				continue;
			}
			if (fnmatch($colorDef->hop, $source->type, FNM_CASEFOLD)
				&& isset($colorDef->{$color})
			) {
				return $colorDef;
			}
		}
		return null;
	}

	/** Get a font tag for the text of a routable message */
	public function getTextColor(RoutableEvent $event, string $where): string {
		$path = $event->path ?? [];

		/** @var ?Source */
		$hop = $path[count($path)-1] ?? null;
		if (empty($event->char) || $event->char->id === $this->chatBot->char->id) {
			if (!isset($hop) || $hop->type !== Source::SYSTEM) {
				$sysColor = $this->settingManager->getString("default_routed_sys_color")??"";
				return $sysColor;
			}
		}
		if (!count($path) || !isset($hop)) {
			return "";
		}
		$color = $this->getHopColor($path, $where, $hop, "text_color");
		if (!isset($color) || !isset($color->text_color)) {
			return "";
		}
		return "<font color=#{$color->text_color}>";
	}

	/**
	 * Check if $via is part of $path
	 *
	 * @param Source[] $path
	 */
	protected function isSentVia(string $via, array $path): bool {
		for ($i = 0; $i < count($path)-1; $i++) {
			$viaName = $path[$i]->type;
			if (isset($path[$i]->name)) {
				$viaName .= "({$path[$i]->name})";
			}
			if (fnmatch($via, $viaName, FNM_CASEFOLD)
				|| fnmatch($via.'(*)', $viaName, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}
}
