<?php

declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use function Amp\call;
use function Amp\Promise\rethrow;
use Amp\{Promise, Success};
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject, UnableToSerializeObject};
use Exception;
use Generator;
use Illuminate\Support\Collection;

use Nadybot\Core\DBSchema\{Route, RouteHopColor, RouteHopFormat};
use Nadybot\Core\Modules\ALTS\{AltsController, NickController};
use Nadybot\Core\ParamClass\{PCharacter, PDuration, PRemove, PWord};
use Nadybot\Core\Routing\{Character, RoutableEvent, RoutableMessage, Source};
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ConfigFile,
	DB,
	EventFeed,
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
		description: "Show Nadynet information",
		accessLevel: "guest",
	),
	NCA\DefineCommand(
		command: "nadynet reset",
		description: "Reset the Nadynet configuration",
		accessLevel: "mod",
	),
	NCA\DefineCommand(
		command: NadynetController::FILTERS,
		description: "Show current filters",
		accessLevel: "member",
	),
	NCA\DefineCommand(
		command: NadynetController::PERM_FILTERS,
		description: "Manage Nadynet permanent filters",
		accessLevel: "mod",
	),
	NCA\DefineCommand(
		command: NadynetController::TEMP_FILTERS,
		description: "Manage Nadynet temporary filters",
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
	public const DB_TABLE = "nadynet_filter_<myname>";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventFeed $eventFeed;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public AltsController $altsCtrl;

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

	/** Enable incoming and outgoing Nadynet messages */
	#[NCA\Setting\Boolean]
	public bool $nadynetEnabled = true;

	/** Number of messages to queue per sender when rate-limiting */
	#[NCA\Setting\Number]
	public int $nadynetQueueSize = 100;

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

	private int $numClients = 0;

	#[NCA\Setup]
	public function setup(): void {
		$this->removeExpiredFilters();
		$this->reloadFilters();
	}

	#[NCA\Event(
		name: "event-feed(room-info)",
		description: "Register Nadynet channels",
	)]
	public function roomInfoHandler(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\RoomInfo);
		if ($event->highwayPackage->room !== "nadynet") {
			return;
		}
		$this->feedSupportsNadynet = true;
		if ($this->nadynetEnabled) {
			$this->registerChannelHandlers();
		}
		$this->numClients = count($event->highwayPackage->users);
	}

	#[NCA\Event(
		name: ["event-feed(join)", "event-feed(leave)"],
		description: "Count Nadynet client",
	)]
	public function roomJoinHandler(LowLevelEventFeedEvent $event): void {
		$package = $event->highwayPackage;
		if (!($package instanceof Highway\Join) && !($package instanceof Highway\Leave)) {
			return;
		}
		if ($package->room !== "nadynet") {
			return;
		}
		$this->numClients += ($package->type === $package::JOIN) ? 1 : -1;
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
			$bucket = $this->buckets[$senderUUID] ?? null;
			if (!isset($bucket)) {
				$bucket = $this->buckets[$senderUUID] = new LeakyBucket(3, 2);
			}
			if ($bucket->getSize() > $this->nadynetQueueSize) {
				$this->logger->info("Queue for {uuid} is over {bucket_size} - dropping message.", [
					"uuid" => $senderUUID,
					"bucket_size" => $this->nadynetQueueSize,
				]);
				return;
			}
			$bucket->push($message);
			$nextMessage = yield $bucket->getNext();
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

	/** Show information about the Nadynet connection */
	#[NCA\HandlesCommand("nadynet")]
	public function nadynetInfoCommand(
		CmdContext $context,
	): void {
		if ($this->nadynetEnabled === false) {
			$context->reply("Nadynet is disabled on this bot.");
			return;
		}
		if (!isset($this->eventFeed->connection)) {
			$context->reply("Not connected to a any feed at all.");
			return;
		}
		if ($this->feedSupportsNadynet === false) {
			$context->reply("Not connected to a Nadynet-capable feed.");
			return;
		}
		$numClients = "{$this->numClients} ".
				$this->text->pluralize("client", $this->numClients);
		$popup = "<header2>How to talk on Nadynet<end>\n".
			"In order to talk to other bots on a Nadynet channel, use\n".
			"<tab><highlight>{$this->nadynetPrefix}&lt;channel&gt; &lt;message&gt;<end> - ".
			"for example <highlight>@chat Good morning, Rubi-Ka!<end>\n".
			"\n".
			"You can also use abbreviations of channels, as long as they are unique:\n".
			"<tab>@c, @ch, or @cha for @chat, but not @p, since that could be @pvp or @pvm.\n".
			"\n".
			"This, of course, only works if the channel you're talking to is routed to\n".
			"Nadynet. By default, the main channel (org-chat or private-chat) of the bot are\n".
			"set up for relaying these messages, and silently discarding those that don't\n".
			"match a channel.\n".
			"\n".
			"<header2>Who can read me?<end>\n".
			"There " . (($this->numClients === 1) ? "is" : "are") . " ".
			"currently <highlight>{$numClients}<end> other than this bot connected to Nadynet.\n".
			"Each of these clients can read your messages - if they have proper routing\n".
			"in place. How many people there are on each client is not exposed.\n".
			"\n".
			"<header2>What can I read?<end>\n".
			"The following Nadynet channels are seen on this bot:";
		foreach (self::CHANNELS as $channel) {
			$recs = $this->msgHub->getReceiversFor("nadynet({$channel})");
			$visMsg = "Not seen on this bot";
			if (count($recs)) {
				$visMsg = "On " . $this->text->enumerate(...$recs);
			}
			$popup .= "\n<tab><highlight>{$channel}<end>: {$visMsg}";
		}
		$popup .= "\n\n<header2>A word about security<end>\n".
			"Nadynet uses an encrypted connection to the central server, but messages\n".
			"sent through it can be accessed by anyone connected. It is a public service\n".
			"available to all bots, regardless of their dimension. In fact, clients don't even\n".
			"need to be on Anarchy Online, so it is theoretically possible to impersonate\n".
			"someone else's account by sending fabricated information to the server.\n".
			"Hence, it is crucial to exercise caution when permanently banning people's\n".
			"messages.\n".
			"\n".
			"This service operates without moderation, as temporary bans provide sufficient\n".
			"authority for self-regulation. Both the server and this bot enforce rate limits\n".
			"on messages per client. Therefore, attempting to overwhelm others with excessive\n".
			"spamming will not be effective.\n".
			"\n".
			"Be excellent to each other!";
		$channelMsg = "Connected to the following Nadynet channels: ".
			$this->text->enumerate(
				...$this->text->arraySprintf("<highlight>%s<end>", ...self::CHANNELS)
			) . " with <highlight>{$numClients}<end> attached";
		$msgs = $this->text->blobWrap(
			$channelMsg . " [",
			$this->text->makeBlob("instructions", $popup, "Instructions how to use Nadynet"),
			"]"
		);
		foreach ($msgs as $msg) {
			$context->reply($msg);
		}
	}

	/** Reset the whole Nadynet configuration and setup default routes and colors */
	#[NCA\HandlesCommand("nadynet reset")]
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
		$deleteIds = [];
		foreach ($routes as $route) {
			$source = $route->getSource();
			$dest = $route->getDest();
			$isNadynetRoute = (strncasecmp($source, "nadynet", 7) === 0)
				|| (strncasecmp($dest, "nadynet", 7) === 0);
			if (!$isNadynetRoute) {
				continue;
			}
			$deleteIds []= $route->getID();
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
			$this->db->table($this->msgHub::DB_TABLE_ROUTES)
				->whereIn("id", $deleteIds)
				->delete();
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
			"/tell <myname> nadynet filters"
		);
		$nadynetCmd = $this->text->makeChatcmd(
			"<symbol>nadynet",
			"/tell <myname> nadynet"
		);
		$blob .= "\n\n".
			"<i>Blocks on your bot have no impact on other bots.</i>\n".
			"<i>Take a moment to consider before permanently blocking players or bots,</i>\n".
			"<i>because accounts can be faked on Nadynet.</i>\n\n".
			"<i>To list all currently active filters, use {$filtersCmd}.</i>\n".
			"<i>For information about Nadynet, use {$nadynetCmd}.</i>";

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

	/**
	 * Route message $message that come in via routing event $event
	 * to the Nadynet channel $channel
	 */
	public function handleIncoming(RoutableEvent $event, string $channel, string $message): bool {
		if (!$this->nadynetEnabled) {
			return false;
		}
		if (!isset($this->eventFeed->connection)) {
			return false;
		}
		$sourceHop = $this->getSourceHop($event);
		if (!isset($sourceHop)) {
			return false;
		}
		if (!$this->msgHub->hasRouteFromTo("nadynet({$channel})", $sourceHop)) {
			$this->logger->info("No route from {target} {source} - not routing to nadynet", [
				"target" => "nadynet({$channel})",
				"source" => $sourceHop,
			]);
			return false;
		}
		rethrow(call(function () use ($event, $channel, $message): Generator {
			$character = clone $event->getCharacter();
			if (isset($character) && !isset($character->id)) {
				$character->id = yield $this->chatBot->getUid2($character->name);
			}
			$message = new Message(
				dimension: $character?->dimension ?? $this->config->dimension,
				bot_uid: $this->chatBot->char->id,
				bot_name: $this->chatBot->char->name,
				sender_uid: $character?->id,
				sender_name: $character?->name ?? $this->chatBot->char->name,
				main: $character ? $this->altsCtrl->getMainOf($character->name) : null,
				nick: $character ? $this->nickCtrl->getNickname($character->name) : null,
				sent: time(),
				channel: $channel,
				message: $message,
			);
			$serializer = new ObjectMapperUsingReflection();
			$hwBody = $serializer->serializeObject($message);
			if (!is_array($hwBody)) {
				$this->logger->warning("Cannot serialize data for Nadynet - dropping", [
					"message" => $message,
				]);
				return;
			}
			$packet = new Highway\Message(room: "nadynet", body: $hwBody);
			yield $this->eventFeed->connection->send($packet);

			if (!$this->nadynetRouteInternally) {
				return;
			}
			$missingReceivers = $this->getInternalRoutingReceivers($event, $channel);

			$rMsg = $this->nnMessageToRoutableMessage($message);
			foreach ($missingReceivers as $missingReceiver) {
				$handler = $this->msgHub->getReceiver($missingReceiver);
				if (isset($handler)) {
					$handler->receive($rMsg, $missingReceiver);
				}
			}
		}));
		return true;
	}

	/** Create a RoutableMessage from a Nadynet message for internal routing only */
	private function nnMessageToRoutableMessage(Message $message): RoutableMessage {
		$rMsg = new RoutableMessage($message->message);
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
		return $rMsg;
	}

	private function getSourceHop(RoutableEvent $event): ?string {
		$senderHops = $event->getPath();
		if (empty($senderHops)) {
			return null;
		}
		$sourceHop = $senderHops[0]->type . "(" . $senderHops[0]->name . ")";
		$emitter = $this->msgHub->getEmitter($sourceHop);
		if (isset($emitter) && !str_contains($emitter->getChannelName(), "(")) {
			return $emitter->getChannelName();
		}
		return $sourceHop;
	}

	/**
	 * Get a list of routing destinations that would receive Nadynet messages
	 * from $channel, but not messages from the origin of $event.
	 *
	 * @return string[]
	 */
	private function getInternalRoutingReceivers(RoutableEvent $event, string $channel): array {
		$sourceHop = $this->getSourceHop($event);
		if (!isset($sourceHop)) {
			return [];
		}
		$relayedTo = array_merge(
			[$sourceHop],
			$this->msgHub->getReceiversFor($sourceHop)
		);
		$nadynetReceivers = $this->msgHub->getReceiversFor("nadynet({$channel})");
		return array_values(
			array_filter(
				$nadynetReceivers,
				fn (string $receiver): bool =>
					count(
						array_filter(
							$relayedTo,
							fn (string $r1): bool => $this->routeDestsMatch($r1, $receiver),
						)
					) === 0
			)
		);
	}

	private function registerChannelHandlers(): void {
		foreach (self::CHANNELS as $channel) {
			if (isset($this->handlers[strtolower($channel)])) {
				continue;
			}
			$handler = new NadynetChannel(strtolower($channel));
			Registry::injectDependencies($handler);
			$this->msgHub
				->registerMessageEmitter($handler)
				->registerMessageReceiver($handler);
			$this->handlers[strtolower($channel)] = $handler;
		}
		if (!isset($this->receiverHandler)) {
			$handler = new NadynetReceiver();
			Registry::injectDependencies($handler);
			$this->msgHub->registerMessageReceiver($handler);
			$this->receiverHandler = $handler;
		}
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

	/** Check if 2 routing destinations are identical */
	private function routeDestsMatch(string $route1, string $route2): bool {
		if (!strpos($route1, '(')) {
			$route1 .= '(*)';
		}
		if (!strpos($route2, '(')) {
			$route2 .= '(*)';
		}
		return fnmatch($route1, $route2, FNM_CASEFOLD)
			|| fnmatch($route2, $route1, FNM_CASEFOLD);
	}
}
