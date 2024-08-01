<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{preg_match};
use Exception;
use Illuminate\Support\Collection;
use JsonException;
use Monolog\Logger;
use Nadybot\Core\DBSchema\{RouteModifier, RouteModifierArgument};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Route,
	DBSchema\RouteHopColor,
	DBSchema\RouteHopFormat,
	Modules\ALTS\AltsController,
	Modules\ALTS\NickController,
	Modules\MESSAGES\MessageHubController,
	Routing\RoutableEvent,
	Routing\Source,
	Types\EventModifier,
	Types\MessageEmitter,
	Types\MessageReceiver,
};
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;

use ReflectionMethod;

use Throwable;

#[NCA\Instance]
class MessageHub {
	public const EVENT_NOT_ROUTED = 0;
	public const EVENT_DISCARDED = 1;
	public const EVENT_DELIVERED = 2;

	/** @var array<string,ClassSpec> */
	public array $modifiers = [];

	/** @var Collection<int,RouteHopColor> */
	public static Collection $colors;

	public bool $routingLoaded = false;

	/** @var list<RoutableEvent> */
	public array $eventQueue = [];

	/** @var array<string,MessageReceiver> */
	protected array $receivers = [];

	/** @var array<string,MessageEmitter> */
	protected array $emitters = [];

	/** @var array<string,array<string,list<MessageRoute>>> */
	protected array $routes = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private BuddylistManager $buddyListManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private NickController $nickController;

	#[NCA\Inject]
	private MessageHubController $msgHubCtrl;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->parseMessageEmitters();
		foreach (get_declared_classes() as $class) {
			if (!is_a($class, EventModifier::class, true)) {
				continue;
			}
			$spec = Util::getClassSpecFromClass($class, NCA\EventModifier::class);
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
		$query = $this->db->table(RouteHopFormat::getTable());
		Source::$format = $query
			->orderByDesc($query->raw($query->colFunc('LENGTH', 'hop')))
			->asObj(RouteHopFormat::class);
	}

	public function loadTagColor(): void {
		$query = $this->db->table(RouteHopColor::getTable());
		static::$colors = $query
			->orderByDesc($query->raw($query->colFunc('LENGTH', 'hop')))
			->orderByDesc($query->raw($query->colFunc('LENGTH', 'where')))
			->orderByDesc($query->raw($query->colFunc('LENGTH', 'via')))
			->asObj(RouteHopColor::class);
	}

	/** Determine the most specific emitter for a channel */
	public function getEmitter(string $channel): ?MessageEmitter {
		$channel = strtolower($channel);
		if (isset($this->emitters[$channel])) {
			return $this->emitters[$channel];
		}
		foreach ($this->emitters as $emitterChannel => $emitter) {
			if (!str_contains($emitterChannel, '(')) {
				$emitterChannel .= '(*)';
			}
			if (fnmatch($emitterChannel, $channel, \FNM_CASEFOLD)) {
				return $emitter;
			}
			if (fnmatch($channel, $emitterChannel, \FNM_CASEFOLD)) {
				return $emitter;
			}
		}
		return null;
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
				implode(', ', $printArgs).
				')'
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

		/** @var list<mixed> */
		$arguments = [];
		$paramPos = 0;
		foreach ($spec->params as $parameter) {
			$value = $params[$parameter->name] ?? null;
			if (isset($value)) {
				switch ($parameter->type) {
					case $parameter::TYPE_BOOL:
						if (!is_string($value) || !in_array($value, ['true', 'false'], true)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'" . implode(', ', (array)$value) . "'<end> given."
							);
						}
						$arguments []= $value === 'true';
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_INT:
						if (!is_string($value) || !preg_match("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'" . implode(', ', (array)$value) . "'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_STRING_ARRAY:
						$arguments []= (array)$value;
						unset($params[$parameter->name]);
						break;
					default:
						foreach ((array)$value as $v) {
							$arguments []= $v;
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
					$ref = new ReflectionMethod($spec->class, '__construct');
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
		if (count($params) > 0) {
			throw new Exception(
				'Unknown parameter' . (count($params) > 1 ? 's' : '').
				' <highlight>'.
				(collect(array_keys($params)))
					->join('<end>, <highlight>', '<end> and <highlight>').
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
			throw new Exception("There was an error setting up the {$name} modifier: " . $e->getMessage(), 0, $e);
		}
	}

	/** Register an object for handling messages for a channel */
	public function registerMessageReceiver(MessageReceiver $messageReceiver): self {
		$channel = $messageReceiver->getChannelName();
		$this->receivers[strtolower($channel)] = $messageReceiver;
		$this->logger->info('Registered new event receiver for {channel}', [
			'channel' => $channel,
		]);
		return $this;
	}

	/** Register an object as an emitter for a channel */
	public function registerMessageEmitter(MessageEmitter $messageEmitter): self {
		$channel = $messageEmitter->getChannelName();
		$this->emitters[strtolower($channel)] = $messageEmitter;
		$this->logger->info('Registered new event emitter for {channel}', [
			'channel' => $channel,
		]);
		return $this;
	}

	/** Unregister an object for handling messages for a channel */
	public function unregisterMessageReceiver(string $channel): self {
		unset($this->receivers[strtolower($channel)]);
		$this->logger->info('Removed event receiver for {channel}', [
			'channel' => $channel,
		]);
		return $this;
	}

	/** Unregister an object as an emitter for a channel */
	public function unregisterMessageEmitter(string $channel): self {
		unset($this->emitters[strtolower($channel)]);
		$this->logger->info('Removed event emitter for {channel}', [
			'channel' => $channel,
		]);
		return $this;
	}

	/** Determine the most specific receiver for a channel */
	public function getReceiver(string $channel): ?MessageReceiver {
		$channel = strtolower($channel);
		if (isset($this->receivers[$channel])) {
			return $this->receivers[$channel];
		}
		foreach ($this->receivers as $receiverChannel => $receiver) {
			if (fnmatch($receiverChannel, $channel, \FNM_CASEFOLD)) {
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
			if (!str_contains($source, '(')) {
				$source .= '(*)';
			}
			if (fnmatch($source, $sender, \FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all the routing targets for a sender
	 *
	 * @return list<string>
	 */
	public function getReceiversFor(string $sender): array {
		$receivers = [];
		$sender = strtolower($sender);
		foreach ($this->routes as $source => $dest) {
			if (!str_contains($source, '(')) {
				$source .= '(*)';
			}
			if (fnmatch($source, $sender, \FNM_CASEFOLD)) {
				foreach ($dest as $destName => $routes) {
					foreach ($routes as $route) {
						$receivers []= $route->getDest();
					}
				}
			}
		}
		return $receivers;
	}

	/** Check if there is a route defined for a MessageSender to a receiver */
	public function hasRouteFromTo(string $sender, string $destination): bool {
		$sender = strtolower($sender);
		foreach ($this->routes as $source => $dest) {
			if (!str_contains($source, '(')) {
				$source .= '(*)';
			}
			if (!fnmatch($source, $sender, \FNM_CASEFOLD)) {
				continue;
			}
			foreach ($dest as $destName => $routes) {
				if (!count($routes)) {
					continue;
				}
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					continue;
				}
				if (fnmatch($receiver->getChannelName(), $destination, \FNM_CASEFOLD)) {
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
		$this->logger->info('Received event to route');
		$path = $event->getPath();
		if (!count($path)) {
			$this->logger->info('Discarding event without path');
			return static::EVENT_NOT_ROUTED;
		}
		$type = strtolower("{$path[0]->type}({$path[0]->name})");
		$eventLogLevel = null;
		if ($path[0]->type === Source::LOG) {
			/**
			 * @phpstan-ignore-next-line
			 *
			 * @psalm-suppress ArgumentTypeCoercion
			 */
			$eventLogLevel = Logger::toMonologLevel($path[0]->name);
		}
		try {
			$this->logger->info('Trying to route {type} - {event}', [
				'type' => $type,
				'event' => $event,
			]);
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
			if (!str_contains($source, '(')) {
				$source .= '(*)';
			}
			if (isset($eventLogLevel)
				&& count($matches = Safe::pregMatch('/^' . preg_quote(Source::LOG, '/') . "\(([a-z]+)\)$/i", $source))
			) {
				try {
					/**
					 * @psalm-suppress ArgumentTypeCoercion
					 *
					 * @phpstan-ignore-next-line
					 */
					$srcLevel = Logger::toMonologLevel($matches[1]);
					if ($eventLogLevel < $srcLevel) {
						continue;
					}
				} catch (Exception $e) {
					continue;
				}
			} elseif (!fnmatch($source, $type, \FNM_CASEFOLD)) {
				continue;
			}
			foreach ($dest as $destName => $routes) {
				$receiver = $this->getReceiver($destName);
				if (!isset($receiver)) {
					$this->logger->info('No receiver registered for {destination}', [
						'destination' => $destName,
					]);
					continue;
				}
				foreach ($routes as $route) {
					if ($route->isDisabled()) {
						$this->logger->info('Routing to {destination} temporarily disabled', [
							'destination' => $destName,
						]);
						$returnStatus = max($returnStatus, static::EVENT_NOT_ROUTED);
						continue;
					}
					$modifiedEvent = $route->modifyEvent($event);
					if (!isset($modifiedEvent)) {
						$this->logger->info('Event filtered away for {destination}', [
							'destination' => $destName,
						]);
						$returnStatus = max($returnStatus, static::EVENT_NOT_ROUTED);
						continue;
					}
					$this->logger->info('Event routed to {destination}', [
						'destination' => $destName,
					]);
					$destination = $route->getDest();
					if (count($matches = Safe::pregMatch("/\((.+)\)$/", $destination))) {
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
		$charLink = '';
		$hopText = '';
		$char = $event->getCharacter();
		// Render "[Name]" instead of "[Name] Name: "
		$isTell = (isset($lastHop) && $lastHop->type === Source::TELL);
		if (isset($char) && !$isTell) {
			$aoSources = [Source::ORG, Source::PRIV, Source::PUB, Source::TELL];
			$nickName = $this->nickController->getNickname($char->name);
			$mainChar = $this->altsController->getMainOf($char->name);
			$routedName = Text::renderPlaceholders(
				$this->msgHubCtrl->routedSenderFormat,
				[
					'char' => $char->name,
					'nick' => $nickName,
					'main' => ($char->name === $mainChar) ? null : $mainChar,
				]
			);
			$routedName = Safe::pregReplace('/^(.+) \(\1\)$/', '$1', $routedName);
			if ($char->dimension !== $this->config->main->dimension) {
				$routedName .= '@' . $this->dimensionToSuffix($char->dimension);
			}
			$charLink = $routedName . ': ';
			if (
				$this->config->main->dimension === $char->dimension
				&& in_array($lastHop->type??null, $aoSources, true)
				&& $withUserLink
			) {
				$charLink = "<a href=user://{$char->name}>{$routedName}</a>: ";
			}
		}
		if (count($hops) > 0) {
			$hopText = implode(' ', $hops) . ' ';
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
		$lastHop = ($hopPos === 0) ? null : $hops[$hopPos-1];
		$name = $source->render($lastHop);
		if (!isset($name)) {
			return null;
		}
		if (!$withColor) {
			return "[{$name}]";
		}
		$color = $this->getHopColor($hops, $where, $source, 'tag_color');
		if (!isset($color)) {
			return "[{$name}]";
		}
		return "<font color=#{$color->tag_color}>[{$name}]<end>";
	}

	public function getCharacter(string $dest): ?string {
		$regExp = '/' . preg_quote(Source::TELL, '/') . "\((.+)\)$/";
		if (!count($matches = Safe::pregMatch($regExp, $dest))) {
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
			$this->buddyListManager->addName($char, 'msg_hub');
		}
		if (!$route->getTwoWay()) {
			return;
		}
		$this->routes[$dest] ??= [];
		$this->routes[$dest][$source] ??= [];
		$this->routes[$dest][$source] []= $route;
		$char = $this->getCharacter($source);
		if (isset($char)) {
			$this->buddyListManager->addName($char, 'msg_hub');
		}
	}

	/** @return list<MessageRoute> */
	public function getRoutes(): array {
		$allRoutes = [];
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $routes) {
				foreach ($routes as $route) {
					$allRoutes [$route->getID()->toString()] = $route;
				}
			}
		}
		return array_values($allRoutes);
	}

	/**
	 * Get a list of commands to re-create all routes
	 *
	 * @return list<string>
	 */
	public function getRouteDump(bool $useForce=false): array {
		$routes = $this->getRoutes();
		$cmd = $useForce ? 'addforce' : 'add';
		return array_map(static function (MessageRoute $route) use ($cmd): string {
			$routeCode = $route->getSource();
			if ($route->getTwoWay()) {
				$routeCode .= ' <-> ';
			} else {
				$routeCode .= ' -> ';
			}
			$routeCode .= $route->getDest();
			$mods = $route->renderModifiers();
			if (count($mods)) {
				$routeCode .= ' ' . implode(' ', $mods);
			}
			return "!route {$cmd} {$routeCode}";
		}, $routes);
	}

	public function deleteRouteID(\Stringable|string $id): ?MessageRoute {
		$id = (string)$id;
		$result = null;
		foreach ($this->routes as $source => $destData) {
			foreach ($destData as $dest => $routes) {
				for ($i = 0; $i < count($routes); $i++) {
					$route = $routes[0];
					if ((string)$route->getID() !== $id) {
						continue;
					}
					$result = $route;
					unset($this->routes[$source][$dest][$i]);
					$char = $this->getCharacter($dest);
					if (isset($char)) {
						$this->buddyListManager->remove($char, 'msg_hub');
					}
					if ($result->getTwoWay()) {
						$char = $this->getCharacter($source);
						if (isset($char)) {
							$this->buddyListManager->remove($char, 'msg_hub');
						}
					}
				}

				/** @psalm-suppress RedundantFunctionCallGivenDocblockType */
				$this->routes[$source][$dest] = array_values(
					$this->routes[$source][$dest]
				);
				if (!count($this->routes[$source][$dest])) {
					unset($this->routes[$source][$dest]);
				}
			}
		}
		return $result;
	}

	/** Remove all routes from the routing table and return how many were removed */
	public function deleteAllRoutes(): int {
		$routes = $this->getRoutes();
		$needTransaction = $this->db->inTransaction() === false;
		if ($needTransaction) {
			$this->db->awaitBeginTransaction();
		}
		try {
			$this->db->table(RouteModifierArgument::getTable())->truncate();
			$this->db->table(RouteModifier::getTable())->truncate();
			$this->db->table(Route::getTable())->truncate();
		} catch (Exception $e) {
			if ($needTransaction) {
				$this->db->rollback();
			}
			throw $e;
		}
		if ($needTransaction) {
			$this->db->commit();
		}
		$this->routes = [];
		return count($routes);
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

	/** @param list<Source> $path */
	public function getHopColor(array $path, string $where, Source $source, string $color): ?RouteHopColor {
		$colorDefs = static::$colors;
		if (isset($source->name)) {
			$fullDefs = $colorDefs->filter(static function (RouteHopColor $color): bool {
				return str_contains($color->hop, '(');
			});
			foreach ($fullDefs as $colorDef) {
				if (!fnmatch($colorDef->hop, "{$source->type}({$source->name})", \FNM_CASEFOLD)) {
					continue;
				}
				$colorWhere = $colorDef->where??'*';
				if (!fnmatch($colorWhere, $where, \FNM_CASEFOLD)
					&& !fnmatch($colorWhere.'(*)', $where, \FNM_CASEFOLD)) {
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
			if (!fnmatch($colorWhere, $where, \FNM_CASEFOLD)
				&& !fnmatch($colorWhere.'(*)', $where, \FNM_CASEFOLD)) {
				continue;
			}
			if (isset($colorDef->via) && !$this->isSentVia($colorDef->via, $path)) {
				continue;
			}
			if (fnmatch($colorDef->hop, $source->type, \FNM_CASEFOLD)
				&& isset($colorDef->{$color})
			) {
				return $colorDef;
			}
		}
		return null;
	}

	/** Get a font tag for the text of a routable message */
	public function getTextColor(RoutableEvent $event, string $where): string {
		$path = $event->path;
		if (!count($path)) {
			return '';
		}

		/** @var ?Source */
		$hop = $path[count($path)-1] ?? null;
		if (!isset($event->char) || $event->char->id === $this->chatBot->char?->id) {
			if (!isset($hop) || $hop->type !== Source::SYSTEM) {
				$sysColor = $this->settingManager->getString('default_routed_sys_color')??'';
				return $sysColor;
			}
		}
		if (!isset($hop)) {
			return '';
		}
		$color = $this->getHopColor($path, $where, $hop, 'text_color');
		if (!isset($color) || !isset($color->text_color)) {
			return '';
		}
		return "<font color=#{$color->text_color}>";
	}

	/**
	 * Check if $via is part of $path
	 *
	 * @param list<Source> $path
	 */
	protected function isSentVia(string $via, array $path): bool {
		for ($i = 0; $i < count($path)-1; $i++) {
			$viaName = $path[$i]->type;
			if (isset($path[$i]->name)) {
				$viaName .= "({$path[$i]->name})";
			}
			if (fnmatch($via, $viaName, \FNM_CASEFOLD)
				|| fnmatch($via.'(*)', $viaName, \FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	private function dimensionToSuffix(int $dimension): string {
		return match ($dimension) {
			4 => 'Test',
			5 => 'RK5',
			6 => 'RK19',
			default => "RK{$dimension}",
		};
	}
}
