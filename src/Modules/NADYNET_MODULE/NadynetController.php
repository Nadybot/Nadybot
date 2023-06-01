<?php

declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use Amp\{Promise, Success};
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\{Route, RouteHopColor, RouteHopFormat};

use Nadybot\Core\Modules\ALTS\NickController;
use Nadybot\Core\ParamClass\{PCharacter, PDuration, PRemove, PWord};
use Nadybot\Core\Routing\{Character, RoutableMessage, Source};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ConfigFile,
	DB,
	EventFeedHandler,
	EventManager,
	Highway,
	LoggerWrapper,
	LowLevelEventFeedEvent,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Registry,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HandlesEventFeed('nadynet'),
	NCA\ProvidesEvent('nadynet(*)'),
	NCA\DefineCommand(
		command: "nadynet",
		description: "Manage nadynet",
		accessLevel: "mod",
	),
	NCA\DefineCommand(
		command: NadynetController::FILTERS,
		description: "Show current filters",
		accessLevel: "member",
	),
	NCA\DefineCommand(
		command: NadynetController::PERM_FILTERS,
		description: "Manage nadynet permanent filters",
		accessLevel: "mod",
	),
	NCA\DefineCommand(
		command: NadynetController::TEMP_FILTERS,
		description: "Manage nadynet temporary filters",
		accessLevel: "guild",
	),
]
class NadynetController extends ModuleInstance implements EventFeedHandler {
	public const FILTERS = "nadynet list filters";
	public const PERM_FILTERS = "nadynet manage permanent filters";
	public const TEMP_FILTERS = "nadynet manage temporary filters";

	public const CHANNELS = [
		"PVM",
		"PVP",
		"Chat",
		"RP",
		"Lootrights",
	];
	public const DB_TABLE = "nadynet_filter";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public MessageHub $msgHub;

	#[NCA\Inject]
	public NickController $nickCtrl;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Process incoming or outgoing Nadynet messages */
	#[NCA\Setting\Boolean]
	public bool $nadynetEnabled = true;

	/** Route outgoing Nadynet messages internally as well */
	#[NCA\Setting\Boolean]
	public bool $nadynetRouteInternally = true;

	/** The prefix to put in front of the channel name to send messages */
	#[NCA\Setting\Text(
		options: ["@", "#", "%", "="]
	)]
	public string $nadynetPrefix = "@";

	public Collection $filters;

	/** @var array<string,NadynetChannel> */
	private array $handlers = [];

	private ?NadynetReceiver $receiverHandler=null;

	/** @var array<string,LeakyBucket> */
	private array $buckets = [];

	private bool $feedSupportsNadynet = false;

	#[NCA\Setup]
	public function setup(): void {
		$callback = function (LowLevelEventFeedEvent $event) use (&$callback): void {
			assert($event->highwayPackage instanceof Highway\RoomInfo);
			if ($event->highwayPackage->room !== "nadynet") {
				return;
			}
			$this->feedSupportsNadynet = true;
			if ($this->nadynetEnabled) {
				$this->registerChannelHandlers();
			}
			$this->eventManager->unsubscribe("event-feed(room-info)", $callback);
		};
		$this->eventManager->subscribe("event-feed(room-info)", $callback);
		$this->removeExpiredFilters();
		$this->reloadFilters();
	}

	#[NCA\SettingChangeHandler("nadynet_enabled")]
	public function switchNadynetStatus(string $setting, string $old, string $new): void {
		if ($new === "1" && $this->feedSupportsNadynet) {
			$this->registerChannelHandlers();
		} else {
			$this->unregisterChannelHandlers();
		}
	}

	public function getPrettyChannelName(string $search): ?string {
		foreach (self::CHANNELS as $channel) {
			if (strtolower($channel) === strtolower($search)) {
				return $channel;
			}
		}
		return null;
	}

	public function reloadFilters(): void {
		$this->filters = $this->db->table(self::DB_TABLE)->asObj(FilterEntry::class);
	}

	public function removeExpiredFilters(): void {
		$this->db->table(self::DB_TABLE)
			->whereNotNull("expires")
			->where("expires", "<=", time())
			->delete();
	}

	#[NCA\Event(
		name: "timer(1m)",
		description: "Remove expired filters",
	)]
	public function cleanExpiredFilters(): void {
		$this->removeExpiredFilters();
		$this->reloadFilters();
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		/** @var Promise<void> */
		$result = new Success();
		return $result;
	}

	#[NCA\Event(
		name: "timer(10s)",
		description: "Clean unused rate limits"
	)]
	public function clearUnusedBuckets(): void {
		$this->buckets = array_filter(
			$this->buckets,
			function (LeakyBucket $bucket): bool {
				$emptySince = $bucket->getEmptySince();
				return isset($emptySince) && $emptySince + 10 < microtime(true);
			}
		);
	}

	#[NCA\Event(
		name: "event-feed(message)",
		description: "Handle raw Nadynet-messages",
	)]
	public function handleLLEventFeedMessage(LowLevelEventFeedEvent $event): Generator {
		if (!$this->nadynetEnabled) {
			return;
		}
		assert($event->highwayPackage instanceof Highway\Message);
		if ($event->highwayPackage->room !== 'nadynet') {
			return;
		}
		$senderUUID = $event->highwayPackage->user;
		if (!isset($senderUUID)) {
			return;
		}
		$body = $event->highwayPackage->body;
		if (is_string($body)) {
			$body = json_decode($body, true);
		}

		$mapper = new ObjectMapperUsingReflection();
		try {
			$message = $mapper->hydrateObject(Message::class, $body);
			if (!$this->isWantedMessage($message)) {
				$this->logger->info("Nadynet message was filtered away.");
				return;
			}
			if (!isset($this->buckets[$senderUUID])) {
				$this->buckets[$senderUUID] = new LeakyBucket(3, 2);
			}
			$this->buckets[$senderUUID]->push($message);
			$nextMessage = yield $this->buckets[$senderUUID]->getNext();
			if (!isset($nextMessage)) {
				return;
			}
			$event = new NadynetEvent(
				type: 'nadynet(' . strtolower($nextMessage->channel) . ')',
				message: $nextMessage
			);
			$this->eventManager->fireEvent($event);
		} catch (UnableToHydrateObject $e) {
			$this->logger->info("Invalid nadynet-package received: {data}.", [
				"data" => $body,
			]);
		}
	}

	#[NCA\Event(name: "nadynet(*)", description: "Handle Nadynet messages")]
	public function handleMessage(NadynetEvent $event): void {
		$message = $event->message;
		$handler = $this->handlers[strtolower($message->channel)]??null;
		if (!isset($handler)) {
			$this->logger->info("No handler for Nadynet channel {channel} found.", [
				"channel" => $message->channel,
			]);
			return;
		}
		$popup = $this->getInfoPopup($message);
		$msgs = $this->text->blobWrap(
			$message->message . " [",
			$this->text->makeBlob("details", $popup, "Message details"),
			"]"
		);
		foreach ($msgs as $msg) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->setCharacter(new Character(
				name: $message->sender_name,
				id: $message->sender_uid,
				dimension: $message->dimension,
			));
			$rMsg->prependPath(new Source(
				type: "nadynet",
				name: strtolower($message->channel),
				label: $message->channel,
				dimension: $message->dimension,
			));
			$this->msgHub->handle($rMsg);
		}
	}

	/** Reset the whole Nadynet configuration and setup default routes and colors */
	#[NCA\HandlesCommand("nadynet")]
	public function nadynetInitCommand(
		CmdContext $context,
		#[NCA\Str("reset", "init")] string $action
	): Generator {
		$colors = $this->msgHub::$colors;

		/** @var int[] */
		$colorIds = $colors->filter(function (RouteHopColor $color): bool {
			return strncasecmp($color->hop, "nadynet", 7) === 0;
		})->pluck("id")->toArray();

		/** @var int[] */
		$formatIds = Source::$format->filter(function (RouteHopFormat $format): bool {
			return strncasecmp($format->hop, "nadynet", 7) === 0;
		})->pluck("id")->toArray();

		$routes = $this->msgHub->getRoutes();
		foreach ($routes as $route) {
			$source = $route->getSource();
			$dest = $route->getDest();
			$isNadynetRoute = (strncasecmp($source, "nadynet", 7) === 0)
				|| (strncasecmp($dest, "nadynet", 7) === 0);
			if (!$isNadynetRoute) {
				continue;
			}
			$this->msgHub->deleteRouteID($route->getID());
		}
		$routes = [];
		$hops = ["web", isset($this->config->orgId) ? "aoorg" : "aopriv({$this->chatBot->char->name})"];

		foreach ($hops as $hop) {
			$route = new Route();
			$route->source = "nadynet(*)";
			$route->two_way = false;
			$route->destination = $hop;
			$routes []= $route;

			$route = new Route();
			$route->source = $hop;
			$route->two_way = false;
			$route->destination = "nadynet";
			$routes []= $route;
		}

		$rhf = new RouteHopFormat();
		$rhf->hop = "nadynet";
		$rhf->render = true;
		$rhf->format = 'nadynet@%s';

		$rhc = new RouteHopColor();
		$rhc->hop = 'nadynet';
		$rhc->tag_color = '00EFFF';
		$rhc->text_color = '00BFFF';

		$msgRoutes = [];
		yield $this->db->awaitBeginTransaction();
		try {
			$this->db->table($this->msgHub::DB_TABLE_COLORS)
				->whereIn("id", $colorIds)
				->delete();
			$this->db->table(Source::DB_TABLE)
				->whereIn("id", $formatIds)
				->delete();
			$this->db->insert($this->msgHub::DB_TABLE_COLORS, $rhc);
			$this->db->insert(Source::DB_TABLE, $rhf);
			foreach ($routes as $route) {
				$route->id = $this->db->insert($this->msgHub::DB_TABLE_ROUTES, $route);
				$msgRoutes []= $this->msgHub->createMessageRoute($route);
			}
		} catch (Exception $e) {
			$this->db->rollback();
			$context->reply($e->getMessage());
			return;
		}
		$this->db->commit();
		foreach ($msgRoutes as $msgRoute) {
			$this->msgHub->addRoute($msgRoute);
		}
		$this->msgHub->loadTagColor();
		$this->msgHub->loadTagFormat();

		$context->reply("Routing for Nadynet initialized.");
	}

	/** Show the currently active filters */
	#[NCA\HandlesCommand(self::FILTERS)]
	public function nadynetListFilters(
		CmdContext $context,
		#[NCA\Str("filters", "filter")] string $action
	): void {
		$this->cleanExpiredFilters();
		$this->reloadFilters();
		if ($this->filters->isEmpty()) {
			$context->reply("No Nadynet filters are currently active.");
			return;
		}
		$blob = "<header2>Currently active filters<end>\n".
			$this->filters
				->map(Closure::fromCallable([$this, "renderFilter"]))
				->join("\n");
		$msg = $this->text->makeBlob(
			"Nadynet filters (" . $this->filters->count() . ")",
			$blob
		);
		$context->reply($msg);
	}

	/** Permanently block Nadynet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function nadynetAddPermanentUserFilters(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		#[NCA\Str("permanent")] string $permanent,
		#[NCA\StrChoice("bot", "sender")] string $where,
		PCharacter $name,
		int $dimension,
	): Generator {
		yield from $this->nadynetAddUserFilter(
			context: $context,
			where: $where,
			name: $name,
			duration: null,
			dimension: $dimension,
		);
	}

	/** Temporarily block Nadynet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function nadynetAddTemporaryUserFilters(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		PDuration $duration,
		#[NCA\StrChoice("bot", "sender")] string $where,
		PCharacter $name,
		int $dimension,
	): Generator {
		yield from $this->nadynetAddUserFilter(
			context: $context,
			where: $where,
			name: $name,
			duration: $duration,
			dimension: $dimension,
		);
	}

	/** Permanently block Nadynet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function nadynetAddPermanentChannelFilters(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		#[NCA\Str("permanent")] string $permanent,
		#[NCA\Str("channel")] string $where,
		PWord $channel,
		?int $dimension,
	): void {
		$this->nadynetAddChannelFilter(
			context: $context,
			duration: null,
			channel: $channel,
			dimension: $dimension,
		);
	}

	/** Temporarily block Nadynet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function nadynetAddTemporaryChannelFilters(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		PDuration $duration,
		#[NCA\Str("channel")] string $where,
		PWord $channel,
		?int $dimension,
	): void {
		$this->nadynetAddChannelFilter(
			context: $context,
			duration: $duration,
			channel: $channel,
			dimension: $dimension,
		);
	}

	/** Permanently block Nadynet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function nadynetAddPermanentDimensionFilter(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		#[NCA\Str("permanent")] string $permanent,
		#[NCA\Str("dimension")] string $where,
		int $dimension,
	): void {
		$this->nadynetAddDimensionFilter($context, null, $dimension);
	}

	/** Temporarily block Nadynet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function nadynetAddTemporaryDimensionFilter(
		CmdContext $context,
		#[NCA\Str("filter", "filters")] string $action,
		PDuration $duration,
		#[NCA\Str("dimension")] string $where,
		int $dimension,
	): void {
		$this->nadynetAddDimensionFilter($context, $duration, $dimension);
	}

	/** Delete a Nadynet filter */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function nadynetDeleteFilter(
		CmdContext $context,
		#[NCA\Str("filter")] string $filter,
		PRemove $action,
		int $id
	): void {
		/** @var ?FilterEntry */
		$filter = $this->db
			->table(self::DB_TABLE)
			->where('id', $id)
			->asObj(FilterEntry::class)
			->first();
		if (!isset($filter)) {
			$context->reply("Nadynet filter <highlight>#{$id}<end> does not exist.");
			return;
		}
		if (!isset($filter->expires)) {
			$hasPermPrivs = $this->cmdManager->couldRunCommand(
				$context,
				"nadynet filter permanent dimension 5"
			);
			if (!$hasPermPrivs) {
				$context->reply("You don't have the required rights to delete permanent filters.");
				return;
			}
		}
		if ($this->db->table(self::DB_TABLE)->delete($filter->id) === 0) {
			$context->reply("There was an unknown error deleting this filter.");
			return;
		}
		$this->reloadFilters();
		$context->reply(
			"Filter <highlight>#{$filter->id}<end> ".
			"(" . $this->getFilterDescr($filter) . ") ".
			"successfully deleted."
		);
	}

	public function dimensionToName(int $dimension): string {
		$map = [
			4 => "Test-Server",
			5 => "Rubi-Ka",
			6 => "RK19",
		];
		return $map[$dimension] ?? "Unknown server";
	}

	public function getInfoPopup(Message $message): string {
		$blob = "<header2>Information about this message<end>";
		$blob .= "\n<tab>Sent: <highlight>" . $this->util->date($message->sent) . "<end>";

		$blockTempLink = $this->text->makeChatcmd(
			"block 15min",
			"/tell <myname> nadynet filter 15m dimension {$message->dimension}"
		);
		$blockPermLink = $this->text->makeChatcmd(
			"block",
			"/tell <myname> nadynet filter permanent dimension {$message->dimension}"
		);
		$blob .= "\n<tab>Dimension: ".
			"<highlight>{$message->dimension} ".
			"(" . $this->dimensionToName($message->dimension) . ")".
			"<end> [{$blockTempLink}] [{$blockPermLink}]";

		$blockTempLink = $this->text->makeChatcmd(
			"block 15min",
			"/tell <myname> nadynet filter 15m channel {$message->channel}"
		);
		$blockPermLink = $this->text->makeChatcmd(
			"block",
			"/tell <myname> nadynet filter permanent channel {$message->channel}"
		);
		$blob .= "\n<tab>Channel: ".
			"<highlight>" . ($this->getPrettyChannelName($message->channel) ?? $message->channel) ."<end> ".
			"[{$blockTempLink}] [{$blockPermLink}]";

		$senderName = $message->sender_name;
		if (isset($message->sender_uid)) {
			$senderName .= " (UID {$message->sender_uid})";
		}
		$blockTempLink = $this->text->makeChatcmd(
			"block 15min",
			"/tell <myname> nadynet filter 15m sender {$message->sender_name} {$message->dimension}"
		);
		$blockPermLink = $this->text->makeChatcmd(
			"block",
			"/tell <myname> nadynet filter permanent sender {$message->sender_name} {$message->dimension}"
		);
		$blob .= "\n<tab>Sender: <highlight>{$senderName}<end> [{$blockTempLink}] [{$blockPermLink}]";

		$botName = $message->bot_name;
		if (isset($message->bot_uid)) {
			$botName .= " (UID {$message->bot_uid})";
		}
		$blockTempLink = $this->text->makeChatcmd(
			"block 15min",
			"/tell <myname> nadynet filter 15m bot {$message->bot_name} {$message->dimension}"
		);
		$blockPermLink = $this->text->makeChatcmd(
			"block",
			"/tell <myname> nadynet filter permanent bot {$message->bot_name} {$message->dimension}"
		);
		$blob .= "\n<tab>Via Bot: <highlight>{$botName}<end> [{$blockTempLink}] [{$blockPermLink}]";

		$filtersCmd = $this->text->makeChatcmd(
			"<symbol>nadynet filters",
			"/tell <myname> nadynet  filters"
		);
		$blob .= "\n\n".
			"<i>Blocks are local on your bot and don't have any effect on any ".
			"other bots.</i>\n".
			"<i>Please think twice before permanently blocking players or bots.</i>\n\n".
			"<i>To list all currently active filters, use {$filtersCmd}.</i>";

		return $blob;
	}

	/** Get a string representation of the filter */
	public function getFilterDescr(FilterEntry $entry): string {
		$parts = [];
		if (isset($entry->bot_name)) {
			$part = "Bot={$entry->bot_name}";
			if (isset($entry->bot_uid)) {
				$part .= " (UID {$entry->bot_uid})";
			}
			$parts []= $part;
		} elseif (isset($entry->bot_uid)) {
			$parts []= "Bot=UID {$entry->bot_uid}";
		}
		if (isset($entry->sender_name)) {
			$part = "Character={$entry->sender_name}";
			if (isset($entry->sender_uid)) {
				$part .= " (UID {$entry->sender_uid})";
			}
			$parts []= $part;
		} elseif (isset($entry->sender_uid)) {
			$parts []= "Character=UID {$entry->sender_uid}";
		}
		if (isset($entry->dimension)) {
			$parts []= "Dimension=" . $this->dimensionToName($entry->dimension).
				" ({$entry->dimension})";
		}
		if (isset($entry->channel)) {
			$displayChannel = $this->getPrettyChannelName($entry->channel) ?? $entry->channel;
			$parts []= "Channel={$displayChannel}";
		}
		return join(" AND ", $parts);
	}

	private function registerChannelHandlers(): void {
		foreach (self::CHANNELS as $channel) {
			$handler = new NadynetChannel(strtolower($channel));
			Registry::injectDependencies($handler);
			$this->msgHub
				->registerMessageEmitter($handler)
				->registerMessageReceiver($handler);
			$this->handlers[strtolower($channel)] = $handler;
		}
		$handler = new NadynetReceiver();
		Registry::injectDependencies($handler);
		$this->msgHub->registerMessageReceiver($handler);
		$this->receiverHandler = $handler;
	}

	private function unregisterChannelHandlers(): void {
		foreach ($this->handlers as $channel => $handler) {
			$this->msgHub
				->unregisterMessageEmitter($handler->getChannelName())
				->unregisterMessageReceiver($handler->getChannelName());
		}
		if (isset($this->receiverHandler)) {
			$this->msgHub->unregisterMessageReceiver("nadynet");
			$this->receiverHandler = null;
		}
	}

	private function nadynetAddDimensionFilter(
		CmdContext $context,
		?PDuration $duration,
		int $dimension,
	): void {
		$entry = new FilterEntry();
		$entry->creator = $context->char->name;
		$entry->dimension = $dimension;
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3600 * 24) {
				$context->reply("You cannot issue a temporary block for over 24h");
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		$entry->id = $this->db->insert(self::DB_TABLE, $entry);
		$this->reloadFilters();
		$context->reply("Filter " . $this->getFilterDescr($entry) . " added.");
	}

	private function renderFilter(FilterEntry $entry): string {
		$line = $this->getFilterDescr($entry);
		if (isset($entry->expires) && $entry->expires > time()) {
			$remaining = $entry->expires - time();
			$line .= " - expires in " . $this->util->unixtimeToReadable($remaining, false);
		}
		$deleteLink = $this->text->makeChatcmd(
			"del",
			"/tell <myname> nadynet filter del {$entry->id}"
		);
		return "<tab>* [{$deleteLink}] <highlight>#{$entry->id}<end> {$line}";
	}

	private function isWantedMessage(Message $message): bool {
		return $this->filters->first(function (FilterEntry $entry) use ($message): bool {
			return $entry->matches($message);
		}) === null;
	}

	private function nadynetAddUserFilter(
		CmdContext $context,
		?PDuration $duration,
		string $where,
		PCharacter $name,
		int $dimension,
	): Generator {
		$entry = new FilterEntry();
		$entry->creator = $context->char->name;
		$entry->dimension = $dimension;
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3600 * 24) {
				$context->reply("You cannot issue a temporary block for over 24h");
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		$uid = null;
		if ($dimension === $this->config->dimension) {
			$uid = yield $this->chatBot->getUid2($name());
			if ($uid === null) {
				$context->reply("The character {$name} does not exist");
				return;
			}
		}
		if ($where === "bot") {
			$entry->bot_name = $name();
			$entry->bot_uid = $uid;
		} else {
			$entry->sender_name = $name();
			$entry->sender_uid = $uid;
		}
		$this->db->insert(self::DB_TABLE, $entry);
		$this->reloadFilters();
		$context->reply("Filter " . $this->getFilterDescr($entry) . " added.");
	}

	private function nadynetAddChannelFilter(
		CmdContext $context,
		?PDuration $duration,
		PWord $channel,
		?int $dimension,
	): void {
		$entry = new FilterEntry();
		$entry->creator = $context->char->name;
		$entry->dimension = $dimension;
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3600 * 24) {
				$context->reply("You cannot issue a temporary block for over 24h");
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		if ($this->getPrettyChannelName($channel()) === null) {
			$context->reply("The channel {$channel} does not exist.");
			return;
		}
		$entry->channel = strtolower($channel());
		$this->db->insert(self::DB_TABLE, $entry);
		$this->reloadFilters();
		$context->reply("Filter " . $this->getFilterDescr($entry) . " added.");
	}
}
