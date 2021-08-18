<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Illuminate\Support\Collection;
use JsonException;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\DBSchema\RouteHopColor;
use ReflectionMethod;
use Throwable;

/**
 * @Instance
 */
class MessageHub {
	public const DB_TABLE_ROUTES = "route_<myname>";
	public const DB_TABLE_COLORS = "route_hop_color_<myname>";
	public const DB_TABLE_TEXT_COLORS = "route_text_color_<myname>";
	public const DB_TABLE_ROUTE_MODIFIER = "route_modifier_<myname>";
	public const DB_TABLE_ROUTE_MODIFIER_ARGUMENT = "route_modifier_argument_<myname>";

	/** @var array<string,MessageReceiver> */
	protected array $receivers = [];

	/** @var array<string,MessageEmitter> */
	protected array $emitters = [];

	/** @var array<string,array<string,MessageRoute>> */
	protected array $routes = [];

	/** @var array<string,ClassSpec> */
	public array $modifiers = [];

	/** @Inject */
	public Text $text;

	/** @Inject */
	public BuddylistManager $buddyListManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$modifierFiles = glob(__DIR__ . "/EventModifier/*.php");
		foreach ($modifierFiles as $file) {
			require_once $file;
			$className = basename($file, '.php');
			$fullClass = __NAMESPACE__ . "\\EventModifier\\{$className}";
			$spec = $this->util->getClassSpecFromClass($fullClass, 'EventModifier');
			if (isset($spec)) {
				$this->registerEventModifier($spec);
			}
		}
	}

	/**
	 * Register an event modifier for public use
	 * @param string $name Name of the modifier
	 * @param FunctionParameter[] $params Name and position of the constructor arguments
	 */
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
	 * @param string $name Name of the modifier
	 * @param array<string,string> $params The parameters of the modifier
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
						if (!in_array($value, ["true", "false"])) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= $value === "true";
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_INT:
						if (!preg_match("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					default:
						$arguments []= (string)$value;
						unset($params[$parameter->name]);
				}
			} elseif ($parameter->required) {
				throw new Exception(
					"Missing required argument <highlight>{$parameter->name}<end> ".
					"to <highlight>{$name}<end>."
				);
			} else {
				$ref = new ReflectionMethod($spec->class, "__construct");
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
		try {
			return new $class(...$arguments);
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} modifier: " . $e->getMessage());
		}
	}

	/**
	 * Register an object for handling messages for a channel
	 */
	public function registerMessageReceiver(MessageReceiver $messageReceiver): self {
		$channel = $messageReceiver->getChannelName();
		$this->receivers[strtolower($channel)] = $messageReceiver;
		$this->logger->log('DEBUG', "Registered new event receiver for {$channel}");
		return $this;
	}

	/**
	 * Register an object as an emitter for a channel
	 */
	public function registerMessageEmitter(MessageEmitter $messageEmitter): self {
		$channel = $messageEmitter->getChannelName();
		$this->emitters[strtolower($channel)] = $messageEmitter;
		$this->logger->log('DEBUG', "Registered new event emitter for {$channel}");
		return $this;
	}

	/**
	 * Unregister an object for handling messages for a channel
	 */
	public function unregisterMessageReceiver(string $channel): self {
		unset($this->receivers[strtolower($channel)]);
		$this->logger->log('INFO', "Removed event receiver for {$channel}");
		return $this;
	}

	/**
	 * Unregister an object as an emitter for a channel
	 */
	public function unregisterMessageEmitter(string $channel): self {
		unset($this->emitters[strtolower($channel)]);
		$this->logger->log('INFO', "Removed event emitter for {$channel}");
		return $this;
	}

	/**
	 * Determine the most specific receiver for a channel
	 */
	public function getReceiver(string $channel): ?MessageReceiver {
		$channel = strtolower($channel);
		if (isset($this->receivers[$channel])) {
			return $this->receivers[$channel];
		}
		foreach ($this->receivers as $receiverChannel => $receiver) {
			if (fnmatch($receiverChannel, $channel)) {
				return $receiver;
			}
		}
		return null;
	}

	/**
	 * Get a list of all message receivers
	 * @return array<string,MessageReceiver>
	 */
	public function getReceivers(): array {
		return $this->receivers;
	}

	/**
	 * Check if there is a route defined for a MessageSender
	 */
	public function hasRouteFor(string $sender): bool {
		$sender = strtolower($sender);
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (fnmatch(strtolower($source), $sender)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get a list of all message emitters
	 * @return array<string,MessageEmitter>
	 */
	public function getEmitters(): array {
		return $this->emitters;
	}

	/**
	 * Submit an event to be routed according to the configured connections
	 */
	public function handle(RoutableEvent $event): void {
		$this->logger->log('INFO', "Received event to route");
		$path = $event->getPath();
		if (empty($path)) {
			$this->logger->log('INFO', "Discarding event without path");
			return;
		}
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			$this->logger->log('INFO', "Discarding non-message event");
			return;
		}
		$type = strtolower("{$path[0]->type}({$path[0]->name})");
		try {
			$this->logger->log(
				'DEBUG',
				"Trying to route {$type} - ".
				json_encode($event, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)
			);
		} catch (JsonException $e) {
			// Ignore
		}
		foreach ($this->routes as $source => $dest) {
			if (!strpos($source, '(')) {
				$source .= '(*)';
			}
			if (!fnmatch(strtolower($source), $type)) {
				continue;
			}
			foreach ($dest as $destName => $route) {
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					$this->logger->log('INFO', "No receiver registered for {$destName}");
					continue;
				}
				$modifiedEvent = $route->modifyEvent($event);
				if (!isset($modifiedEvent)) {
					$this->logger->log('INFO', "Event filtered away for {$destName}");
					continue;
				}
				$this->logger->log('INFO', "Event routed to {$destName}");
				$destination = $route->getDest();
				if (preg_match("/\((.+)\)$/", $destination, $matches)) {
					$destination = $matches[1];
				}
				$receiver->receive($modifiedEvent, $destination);
			}
		}
	}

	/** Get the text to prepend to a message to denote its source path */
	public function renderPath(RoutableEvent $event, bool $withColor=true): string {
		$hops = [];
		$lastHop = null;
		foreach ($event->getPath() as $hop) {
			$renderedHop = $this->renderSource($hop, $lastHop, $withColor);
			if (isset($renderedHop)) {
				$hops []= $renderedHop;
			}
			$lastHop = $hop;
		}
		$charLink = "";
		$hopText = "";
		$char = $event->getCharacter();
		if (isset($char)) {
			$charLink = $this->text->makeUserlink($char->name) . ": ";
		}
		if (!empty($hops)) {
			$hopText = join(" ", $hops) . " ";
		}
		return $hopText.$charLink;
	}

	public function renderSource(Source $source, ?Source $lastHop, bool $withColor): ?string {
		$name = $source->render($lastHop);
		if (!isset($name)) {
			return null;
		}
		if (!$withColor) {
			return "[{$name}]";
		}
		$color = $this->getHopColor($source->type, $source->name, "tag_color");
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

	/**
	 * Add a route to the routing table, either adding or replacing
	 */
	public function addRoute(MessageRoute $route): void {
		$source = $route->getSource();
		$dest = $route->getDest();

		$this->routes[$source] ??= [];
		$this->routes[$source][$dest] = $route;
		$char = $this->getCharacter($dest);
		if (isset($char)) {
			$this->buddyListManager->add($char, "msg_hub");
		}
		if (!$route->getTwoWay()) {
			return;
		}
		$this->routes[$dest] ??= [];
		$this->routes[$dest][$source] = $route;
		$char = $this->getCharacter($source);
		if (isset($char)) {
			$this->buddyListManager->add($char, "msg_hub");
		}
	}

	/**
	 * @return MessageRoute[]
	 */
	public function getRoutes(): array {
		$ids = [];
		$routes = [];
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $route) {
				if (isset($ids[$route->getID()])) {
					continue;
				}
				$routes []= $route;
				$ids[$route->getID()] = true;
			}
		}
		return $routes;
	}

	public function deleteRouteID(int $id): ?MessageRoute {
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $route) {
				if ($route->getID() !== $id) {
					continue;
				}
				$result = $this->routes[$source][$dest];
				unset($this->routes[$source][$dest]);
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
				return $result;
			}
		}
		return null;
	}

	/**
	 * Convert a DB-representation of a route to the real deal
	 * @param Route $route The DB representation
	 * @return MessageRoute The actual message route
	 * @throws Exception whenever this is impossible
	 */
	public function createMessageRoute(Route $route): MessageRoute {
		$msgRoute = new MessageRoute($route);
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

	public function getHopColor(string $type, string $name, string $color): ?RouteHopColor {
		$query = $this->db->table(static::DB_TABLE_COLORS);
		/** @var Collection<RouteHopColor> */
		$colorDefs = $query->orderByDesc($query->colFunc("LENGTH", "hop"))
			->asObj(RouteHopColor::class);
		if (isset($name)) {
			$fullDefs = $colorDefs->filter(function (RouteHopColor $color): bool {
				return strpos($color->hop, "(") !== false;
			});
			foreach ($fullDefs as $colorDef) {
				if (fnmatch($colorDef->hop, "{$type}({$name})", FNM_CASEFOLD)
					&&  isset($colorDef->{$color})
				) {
					return $colorDef;
				}
			}
		}
		foreach ($colorDefs as $colorDef) {
			if (fnmatch($colorDef->hop, $type, FNM_CASEFOLD)
				&& isset($colorDef->{$color})
			) {
				return $colorDef;
			}
		}
		return null;
	}

	/**
	 * Get a font tag for the text of a routable message
	 */
	public function getTextColor(RoutableEvent $event): string {
		if (empty($event->char) || $event->char->id === $this->chatBot->char->id) {
			$sysColor = $this->settingManager->getString("default_routed_sys_color");
			return $sysColor;
		}
		if (!count($event->path)) {
			return "";
		}
		$hop = $event->path[count($event->path)-1] ?? null;
		if (!isset($hop)) {
			return "";
		}
		$color = $this->getHopColor($hop->type, $hop->name, "text_color");
		if (!isset($color) || !isset($color->text_color)) {
			return "";
		}
		return "<font color=#{$color->text_color}>";
	}
}
