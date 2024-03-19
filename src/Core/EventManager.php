<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\Future\await;
use function Amp\{async, delay};
use function Safe\preg_match;

use Closure;
use Exception;
use Nadybot\Core\Event\{ConnectEvent, SetupEvent, TimerEvent};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\EventCfg,
	Modules\MESSAGES\MessageHubController,
};
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Revolt\EventLoop;
use Throwable;

#[NCA\Instance]
class EventManager {
	public const DB_TABLE = "eventcfg_<myname>";
	public const PACKET_TYPE_REGEX = '/packet\(\d+\)/';
	public const TIMER_EVENT_REGEX = '/timer\(([0-9a-z]+)\)/';

	/** @var array<string,string[]> */
	public array $events = [];

	/** @var array<string,callable[]> */
	public array $dynamicEvents = [];
	protected bool $eventsReady = false;

	/**
	 * Events that were disabled before eventhandler was initialized
	 *
	 * @var array<string,array<string,bool>>
	 */
	protected array $dontActivateEvents = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MessageHubController $messageHubController;

	/** @var CronEntry[] */
	private array $cronevents = [];

	/** @var array<string,EventType> */
	private array $eventTypes = [];

	private bool $areConnectEventsFired = false;

	public function __construct() {
		foreach ([
			'msg', 'priv', 'extpriv', 'guild', 'joinpriv', 'leavepriv',
			'extjoinpriv', 'extleavepriv', 'sendmsg', 'sendpriv', 'sendguild',
			'orgmsg', 'extjoinprivrequest', 'logon', 'logoff', 'towers',
			'connect', 'setup', 'pong', 'otherleavepriv',
		] as $event) {
			$type = new EventType();
			$type->name = $event;
			$this->eventTypes[$event] = $type;
		}
	}

	/** Registers an event on the bot so it can be configured */
	public function register(string $module, string $type, string $filename, string $description='none', ?string $help='', ?int $defaultStatus=null): void {
		$type = strtolower($type);

		$this->logger->info("Registering event Type:({type}) Handler:({handler}) Module:({module})", [
			"type" => $type,
			"handler" => $filename,
			"module" => $module,
		]);

		if (!$this->isValidEventType($type) && $this->getTimerEventTime($type) === 0) {
			$this->logger->error("Error registering event Type {type}, Handler {handler} in Module {module}: {error}", [
				"error" => "The type is not a recognized event type",
				"type" => $type,
				"handler" => $filename,
				"module" => $module,
			]);
			return;
		}

		[$name, $method] = explode(".", $filename);
		if (!Registry::instanceExists($name)) {
			$this->logger->error("Error registering handler {handler} for event type {type}: Could not find instance '{instance}'.", [
				"type" => $type,
				"handler" => $filename,
				"instance" => $name,
			]);
			return;
		}

		try {
			if (isset($this->chatBot->existing_events[$type][$filename])) {
				$this->db->table(self::DB_TABLE)
					->where("type", $type)
					->where("file", $filename)
					->where("module", $module)
					->update([
						"verify" => 1,
						"description" => $description,
						"help" => $help,
					]);
				return;
			}
			if ($defaultStatus === null) {
				if ($this->config->general->defaultModuleStatus) {
					$status = 1;
				} else {
					$status = 0;
				}
			} else {
				$status = $defaultStatus;
			}
			$this->db->table(self::DB_TABLE)
					->insert([
						"module" => $module,
						"type" => $type,
						"file" => $filename,
						"verify" => 1,
						"description" => $description,
						"status" => $status,
						"help" => $help,
					]);
		} catch (SQLException $e) {
			$this->logger->error("Error registering handler {handler} for event type {type}: {error}", [
				"handler" => $filename,
				"type" => $type,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/** Activates an event */
	public function activate(string $type, string $filename): void {
		$type = strtolower($type);
		$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $filename]);

		$this->logger->info("Activating event {event}", ["event" => $logObj]);

		[$name, $method] = explode(".", $filename);
		if (!Registry::instanceExists($name)) {
			$this->logger->error("Error activating {event}: Could not find instance '{instance}'.", [
				"event" => $logObj,
				"instance" => $name,
			]);
			return;
		}

		if ($type == "setup") {
			$this->callEventHandler(new SetupEvent(), $filename, []);
		} elseif ($type === "connect" && $this->areConnectEventsFired) {
			$this->callEventHandler(new ConnectEvent(), $filename, []);
		} elseif ($this->isValidEventType($type)) {
			if (!isset($this->events[$type]) || !in_array($filename, $this->events[$type])) {
				$this->events[$type] []= $filename;
			} elseif ($this->chatBot->isReady()) {
				$this->logger->error("Error activating {event}: Event already activated!", [
					"event" => $logObj,
				]);
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$key = $this->getKeyForCronEvent($time, $filename);
				if ($key === null) {
					$entry = new CronEntry(
						nextevent: 0,
						filename: $filename,
						time: $time,
					);
					Registry::injectDependencies($entry);
					$this->startCron($entry);
					$this->cronevents []= $entry;
				} else {
					$this->logger->error("Error activating event {event}: Event already activated!", [
						"event" => $logObj,
					]);
				}
			} else {
				$this->logger->error("Error activating event {event}: The type is not a recognized event type!", [
					"event" => $logObj,
				]);
			}
		}
	}

	/** Subscribe to an event */
	public function subscribe(string $type, callable $callback): void {
		$type = strtolower($type);
		$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $callback]);

		if ($type == "setup") {
			return;
		}
		if ($this->isValidEventType($type)) {
			$this->dynamicEvents[$type] ??= [];
			if (!in_array($callback, $this->dynamicEvents[$type], true)) {
				$this->dynamicEvents[$type] []= $callback;
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$this->logger->error("Dynamic timers are currently not supported");
			} else {
				$this->logger->error("Error activating event {event}: The type is not a recognized event type!", [
					"event" => $logObj,
				]);
			}
		}
	}

	/** Unsubscribe from an event */
	public function unsubscribe(string $type, callable $callback): void {
		$type = strtolower($type);
		$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $callback]);

		if ($type == "setup") {
			return;
		}
		if ($this->isValidEventType($type)) {
			if (!isset($this->dynamicEvents[$type])) {
				return;
			}

			$this->dynamicEvents[$type] = array_values(
				array_filter(
					$this->dynamicEvents[$type],
					function ($c) use ($callback): bool {
						return $c !== $callback;
					}
				)
			);
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$error = "Dynamic timers are currently not supported";
			} else {
				$error = "The type is not a recognized event type";
			}
			$this->logger->error("Error unsubscribing from {event}: {error}", [
				"event" => $logObj,
				"error" => $error,
			]);
		}
	}

	/** Change the time when a cron event gets called next time */
	public function setCronNextEvent(int $key, int $nextEvent): bool {
		$entry = $this->cronevents[$key] ?? null;
		if (!isset($entry) || !isset($entry->handle)) {
			return false;
		}
		EventLoop::disable($entry->handle);
		if (isset($entry->moveHandle)) {
			EventLoop::cancel($entry->moveHandle);
		}
		$delay = max(0, $nextEvent - time());
		$this->logger->notice("Moved the next {event} event to {time}", [
			"event" => $entry->filename,
			"time" => $this->util->date($nextEvent),
		]);
		$entry->moveHandle = EventLoop::delay(
			$delay,
			function () use ($entry): void {
				EventLoop::enable($entry->handle);
				unset($entry->moveHandle);
			}
		);
		return true;
	}

	/** Deactivates an event */
	public function deactivate(string $type, string $filename): void {
		$type = strtolower($type);
		$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $filename]);

		$this->logger->info("Deactivating {event}", ["event" => $logObj]);

		$found = false;
		if ($this->isValidEventType($type)) {
			if (in_array($filename, $this->events[$type]??[])) {
				$found = true;
				$temp = array_flip($this->events[$type]);
				unset($this->events[$type][$temp[$filename]]);
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$key = $this->getKeyForCronEvent($time, $filename);
				if ($key !== null) {
					$found = true;
					EventLoop::cancel($this->cronevents[$key]->moveHandle ?? '');
					EventLoop::cancel($this->cronevents[$key]->handle ?? '');
					unset($this->cronevents[$key]);
				}
			} else {
				$this->logger->error("Error deactivating {event}: {error}", [
					"event" => $logObj,
					"error" => "The type is not a recognized event type!",
				]);
				return;
			}
		}

		if (!$found) {
			$this->logger->error("Error deactivating {event}: {error}", [
				"event" => $logObj,
				"error" => "The event is not active or doesn't exist!",
			]);
		}
	}

	/**
	 * Activates events that are annotated on one or more method names
	 * if the events are not already activated
	 */
	public function activateIfDeactivated(object $obj, string ...$eventMethods): void {
		foreach ($eventMethods as $eventMethod) {
			$filename = Registry::formatName(get_class($obj));
			$call = $filename . "." . $eventMethod;
			$type = $this->getEventTypeByMethod($obj, $eventMethod);
			$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $call]);
			$this->logger->info("Trying to active {event} if it was disabled", [
				"event" => $logObj,
			]);
			if ($type === null) {
				$this->logger->error("Could not find any events for handler '{handler}'", [
					"handler" => $call,
				]);
				return;
			}
			if ($this->isValidEventType($type)) {
				if (isset($this->events[$type]) && in_array($call, $this->events[$type])) {
					// event already activated
					continue;
				}
				$this->activate($type, $call);
			} else {
				$time = $this->getTimerEventTime($type);
				if ($time > 0) {
					$key = $this->getKeyForCronEvent($time, $call);
					if ($key === null) {
						$entry = new CronEntry(
							nextevent: 0,
							filename: $call,
							time: $time,
						);
						Registry::injectDependencies($entry);
						$this->startCron($entry);
					}
				} else {
					$this->logger->error("Error activating {event}: {error}", [
						"event" => $logObj,
						"error" => "The type is not a recognized event type",
					]);
				}
			}
		}
	}

	/**
	 * Deactivates events that are annotated on one or more method names
	 * if the events are not already deactivated
	 */
	public function deactivateIfActivated(object $obj, string ...$eventMethods): void {
		foreach ($eventMethods as $eventMethod) {
			$call = Registry::formatName(get_class($obj)) . "." . $eventMethod;
			$type = $this->getEventTypeByMethod($obj, $eventMethod);
			$logObj = new AnonObj(class: "Event", properties: ["type" => $type, "handler" => $call]);
			$this->logger->info("Trying to deactivate {event} if it was active", [
				"event" => $logObj,
			]);
			if ($type === null) {
				$this->logger->error("Could not find event for '{handler}'", [
					"handler" => $call,
				]);
				return;
			}
			if ($this->isValidEventType($type)) {
				if (!isset($this->events[$type]) || !in_array($call, $this->events[$type])) {
					// event already deactivated
					continue;
				}
				$this->deactivate($type, $call);
			} else {
				$time = $this->getTimerEventTime($type);
				if ($time > 0) {
					if ($this->eventsReady === false) {
						$this->dontActivateEvents[$type] ??= [];
						$this->dontActivateEvents[$type][$call] = true;
					} else {
						$key = $this->getKeyForCronEvent($time, $call);
						if ($key !== null) {
							EventLoop::cancel($this->cronevents[$key]->moveHandle ?? '');
							EventLoop::cancel($this->cronevents[$key]->handle ?? '');
							unset($this->cronevents[$key]);
						}
					}
				} else {
					$this->logger->error("Error deactivating {event}: {error}", [
						"event" => $logObj,
						"error" => "The type is not a recognized event type",
					]);
				}
			}
		}
	}

	public function getEventTypeByMethod(object $obj, string $methodName): ?string {
		$method = new ReflectionMethod($obj, $methodName);
		foreach ($method->getAttributes(NCA\Event::class) as $event) {
			/** @var NCA\Event */
			$eventObj = $event->newInstance();
			foreach ((array)$eventObj->name as $eventName) {
				return strtolower($eventName);
			}
		}
		return null;
	}

	public function getKeyForCronEvent(int $time, string $filename): ?int {
		foreach ($this->cronevents as $key => $event) {
			if ($time === $event->time && $event->filename === $filename) {
				return $key;
			}
		}
		return null;
	}

	/** Loads the active events into memory and activates them */
	public function loadEvents(): void {
		$this->logger->info("Loading enabled events");

		$this->db->table(self::DB_TABLE)
			->where("status", 1)
			->asObj(EventCfg::class)
			->each(function (EventCfg $row): void {
				if (isset($this->dontActivateEvents[$row->type][$row->file])) {
					unset($this->dontActivateEvents[$row->type][$row->file]);
				} elseif (isset($row->type, $row->file)) {
					$this->activate($row->type, $row->file);
				}
			});
		$this->eventsReady = true;
		$this->dontActivateEvents = [];
	}

	/** Execute Events that needs to be executed right after login */
	public function executeConnectEvents(): void {
		if ($this->areConnectEventsFired) {
			return;
		}
		$this->areConnectEventsFired = true;

		$this->logger->info("Executing connected events");
		$this->messageHubController->loadRouting();

		$this->fireEvent(new ConnectEvent());
	}

	public function isValidEventType(string $type): bool {
		if (isset($this->eventTypes[$type])) {
			return true;
		}
		if (preg_match(self::PACKET_TYPE_REGEX, $type) === 1) {
			return true;
		}
		foreach ($this->eventTypes as $check => $event) {
			if (fnmatch($type, $check, FNM_CASEFOLD)) {
				return true;
			}
			if (strpos($check, "*") !== false && fnmatch($check, $type, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	public function getTimerEventTime(string $type): int {
		if (!count($arr = Safe::pregMatch(self::TIMER_EVENT_REGEX, $type))) {
			return 0;
		}
		$time = $this->util->parseTime($arr[1]);
		if ($time > 0) {
			return $time;
		}
		return 0;
	}

	/**
	 * Fire an event by calling all registered event handlers
	 *
	 * @return bool true if at least one event requests to stop execution
	 */
	public function fireEvent(Event $eventObj, mixed ...$args): bool {
		// $this->logger->notice("Event {event} fired", ["event" => $eventObj]);
		$futures = [];
		try {
			foreach ($this->events as $type => $handlers) {
				if ($eventObj->type !== $type && !fnmatch($type, $eventObj->type, FNM_CASEFOLD)) {
					continue;
				}
				foreach ($handlers as $filename) {
					$futures []= async($this->callEventHandler(...), $eventObj, $filename, $args);
				}
			}
			foreach ($this->dynamicEvents as $type => $handlers) {
				if ($eventObj->type !== $type && !fnmatch($type, $eventObj->type, FNM_CASEFOLD)) {
					continue;
				}
				foreach ($handlers as $callback) {
					if (!is_object($callback) || !($callback instanceof Closure)) {
						$callback = Closure::fromCallable($callback);
					}
					$refMeth = new ReflectionFunction($callback);
					$newEventObj = $this->convertSyncEvent($refMeth, $eventObj);
					if (isset($newEventObj)) {
						$futures []= async($callback, $newEventObj, ...$args);
					}
				}
			}
			if (!count($futures)) {
				return false;
			}
			$this->logger->info("Processing {num_events} {event_type} in parallel.", [
				"num_events" => count($futures),
				"event_type" => $eventObj->type,
			]);
			await($futures);
		} catch (StopExecutionException) {
			return true;
		}
		return false;
	}

	/**
	 * @param mixed[] $args
	 *
	 * @throws StopExecutionException
	 */
	public function callEventHandler(Event $eventObj, string $handler, array $args): void {
		$logObj = new AnonObj(
			class: "Event",
			properties: [
				"type" => $eventObj->type,
				"handler" => $handler,
			]
		);
		$this->logger->info("Executing {event}", ["event" => $logObj]);

		try {
			[$name, $method] = explode(".", $handler);
			$instance = Registry::getInstance($name);
			if ($instance === null) {
				$this->logger->error("Could not find instance for class {class} of {event}", [
					"event" => $logObj,
					"class" => $name,
				]);
			} else {
				$refMeth = new ReflectionMethod($instance, $method);
				$eventObj = $this->convertSyncEvent($refMeth, $eventObj);
				if (isset($eventObj)) {
					$instance->{$method}($eventObj, ...$args);
				}
			}
		} catch (StopExecutionException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->error("Error calling handler of {event}: {error}", [
				"event" => $logObj,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/** Dynamically add an event to the allowed types */
	public function addEventType(string $eventType, ?string $description=null): bool {
		$eventType = strtolower($eventType);

		if (isset($this->eventTypes[$eventType])) {
			$this->logger->warning("Event type '{type}' already registered", [
				"type" => $eventType,
			]);
			return false;
		}
		$this->eventTypes[$eventType] = new EventType();
		$this->eventTypes[$eventType]->name = $eventType;
		$this->eventTypes[$eventType]->description = $description;
		return true;
	}

	/**
	 * Get a list of all registered event types
	 *
	 * @return array<string,EventType>
	 */
	public function getEventTypes(): array {
		return $this->eventTypes;
	}

	protected function convertSyncEvent(ReflectionFunctionAbstract $refMeth, Event $eventObj): ?Event {
		return $eventObj;
/*
		if (get_class($eventObj) !== SyncEvent::class) {
			return $eventObj;
		}
		$params = $refMeth->getParameters();
		if (!count($params) || ($type = $params[0]->getType()) === null) {
			return $eventObj;
		}
		if (!($type instanceof ReflectionNamedType)) {
			return $eventObj;
		}
		$class = $type->getName();
		if (!is_subclass_of($class, SyncEvent::class)) {
			return $eventObj;
		}
		try {
			$typedEvent = $class::fromSyncEvent($eventObj);
		} catch (Throwable $e) {
			return null;
		}
		return $typedEvent;
	*/
	}

	private function startCron(CronEntry $entry): void {
		$entry->handle = EventLoop::defer(fn () => $this->startCronRun($entry));
	}

	private function startCronRun(CronEntry $entry): void {
		while (!$this->chatBot->isReady()) {
			delay(0.1);
		}
		$eventObj = new TimerEvent($entry->time);

		$entry->nextevent = time() + $entry->time;
		$this->logger->info("Initial call to {handler}", ["handler" => $entry->filename]);
		$this->callEventHandler($eventObj, $entry->filename, [$entry->time]);
		$period = $entry->time;
		$this->logger->info("Periodic call set up for {handler} every {period}s", [
			"handler" => $entry->filename,
			"period" => $period,
		]);
		$entry->handle = EventLoop::repeat(
			$period,
			function () use ($eventObj, $entry): void {
				$this->logger->info("Periodic call to {handler}", [
					"handler" => $entry->filename,
				]);
				$entry->nextevent = time() + $entry->time;
				$this->callEventHandler($eventObj, $entry->filename, [$entry->time]);
			}
		);
	}
}
