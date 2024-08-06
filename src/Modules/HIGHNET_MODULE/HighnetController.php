<?php

declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use function Safe\json_decode;

use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Exception;

use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\{Route, RouteHopColor, RouteHopFormat};
use Nadybot\Core\Modules\ALTS\{AltsController, NickController};
use Nadybot\Core\ParamClass\{PCharacter, PDuration, PRemove, PUuid, PWord};
use Nadybot\Core\Routing\{Character, RoutableEvent, RoutableMessage, Source};

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	DB,
	EventFeed,
	EventManager,
	Events\LowLevelEventFeedEvent,
	Highway,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Registry,
	Text,
	Types\EventFeedHandler,
	Util,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HandlesEventFeed('highnet'),
	NCA\ProvidesEvent(HighnetEvent::class),
	NCA\DefineCommand(
		command: 'highnet',
		description: 'Show Highnet information',
		accessLevel: 'guest',
	),
	NCA\DefineCommand(
		command: 'highnet reset',
		description: 'Reset the Highnet configuration',
		accessLevel: 'mod',
	),
	NCA\DefineCommand(
		command: HighnetController::FILTERS,
		description: 'Show current filters',
		accessLevel: 'member',
	),
	NCA\DefineCommand(
		command: HighnetController::PERM_FILTERS,
		description: 'Manage Highnet permanent filters',
		accessLevel: 'mod',
	),
	NCA\DefineCommand(
		command: HighnetController::TEMP_FILTERS,
		description: 'Manage Highnet temporary filters',
		accessLevel: 'guild',
	),
]
class HighnetController extends ModuleInstance implements EventFeedHandler {
	public const FILTERS = 'highnet list filters';
	public const PERM_FILTERS = 'highnet manage permanent filters';
	public const TEMP_FILTERS = 'highnet manage temporary filters';

	public const CHANNELS = [
		'PVM',
		'PVP',
		'Chat',
		'RP',
		'Lootrights',
	];

	/** Enable incoming and outgoing Highnet messages */
	#[NCA\Setting\Boolean]
	public bool $highnetEnabled = true;

	/** Number of messages to queue per sender when rate-limiting */
	#[NCA\Setting\Number]
	public int $highnetQueueSize = 100;

	/** Route outgoing Highnet messages internally as well */
	#[NCA\Setting\Boolean]
	public bool $highnetRouteInternally = true;

	/** The prefix to put in front of the channel name to send messages */
	#[NCA\Setting\Text(
		options: ['@', '#', '%', '=']
	)]
	public string $highnetPrefix = '@';

	/** @var list<string> */
	public array $channels = [];

	/** @var Collection<int,FilterEntry> */
	public Collection $filters;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventFeed $eventFeed;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private CommandManager $cmdManager;

	#[NCA\Inject]
	private AltsController $altsCtrl;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private NickController $nickCtrl;

	/** @var array<string,HighnetChannel> */
	private array $handlers = [];

	private ?HighnetReceiver $receiverHandler=null;

	/** @var array<string,LeakyBucket> */
	private array $buckets = [];

	private bool $feedSupportsHighnet = false;

	private int $numClients = 0;

	#[NCA\Setup]
	public function setup(): void {
		$this->removeExpiredFilters();
		$this->reloadFilters();
	}

	#[NCA\Event(
		name: 'event-feed(room-info)',
		description: 'Register Highnet channels',
	)]
	public function roomInfoHandler(LowLevelEventFeedEvent $event): void {
		$package = $event->highwayPackage;
		assert($package instanceof Highway\In\RoomInfo);
		if ($package->room !== 'highnet') {
			return;
		}
		if (
			is_array($package->extraInfo)
			&& isset($package->extraInfo['channels'])
			&& is_array($package->extraInfo['channels'])
		) {
			$this->channels = array_values($package->extraInfo['channels']);
		}
		$this->feedSupportsHighnet = true;
		if ($this->highnetEnabled) {
			$this->registerChannelHandlers();
		}
		$this->numClients = count($package->users);
	}

	#[NCA\Event(
		name: ['event-feed(join)', 'event-feed(leave)'],
		description: 'Count Highnet client',
	)]
	public function roomJoinHandler(LowLevelEventFeedEvent $event): void {
		$package = $event->highwayPackage;
		if (!($package instanceof Highway\In\Join) && !($package instanceof Highway\In\Leave)) {
			return;
		}
		if ($package->room !== 'highnet') {
			return;
		}
		$this->numClients += ($package->type === $package::JOIN) ? 1 : -1;
	}

	#[NCA\SettingChangeHandler('highnet_enabled')]
	public function switchHighnetStatus(string $setting, string $old, string $new): void {
		if ($new === '1' && $this->feedSupportsHighnet) {
			$this->registerChannelHandlers();
		} else {
			$this->unregisterChannelHandlers();
		}
	}

	public function getPrettyChannelName(string $search): ?string {
		foreach ($this->channels as $channel) {
			if (strtolower($channel) === strtolower($search)) {
				return $channel;
			}
		}
		return null;
	}

	public function reloadFilters(): void {
		$this->filters = $this->db->table(FilterEntry::getTable())->asObj(FilterEntry::class);
	}

	public function removeExpiredFilters(): void {
		$this->db->table(FilterEntry::getTable())
			->whereNotNull('expires')
			->where('expires', '<=', time())
			->delete();
	}

	#[NCA\Event(
		name: 'timer(1m)',
		description: 'Remove expired filters',
	)]
	public function cleanExpiredFilters(): void {
		$this->removeExpiredFilters();
		$this->reloadFilters();
	}

	/** @param array<string,mixed> $data */
	public function handleEventFeedMessage(string $room, array $data): void {
		// Nothing to do right now
	}

	#[NCA\Event(
		name: 'timer(10s)',
		description: 'Clean unused rate limits'
	)]
	public function clearUnusedBuckets(): void {
		$this->buckets = array_filter(
			$this->buckets,
			static function (LeakyBucket $bucket): bool {
				$emptySince = $bucket->getEmptySince();
				return isset($emptySince) && $emptySince + 10 < microtime(true);
			}
		);
	}

	#[NCA\Event(
		name: 'event-feed(message)',
		description: 'Handle raw Highnet-messages',
	)]
	public function handleLLEventFeedMessage(LowLevelEventFeedEvent $event): void {
		if (!$this->highnetEnabled) {
			return;
		}
		assert($event->highwayPackage instanceof Highway\In\Message);
		if ($event->highwayPackage->room !== 'highnet') {
			return;
		}
		$senderUUID = $event->highwayPackage->user;
		// if (!isset($senderUUID)) {
		// 	return;
		// }
		$body = $event->highwayPackage->body;
		if (is_string($body)) {
			$body = json_decode($body, true);
		}

		$mapper = new ObjectMapperUsingReflection();
		try {
			$message = $mapper->hydrateObject(Message::class, $body);
			if (!$this->isWantedMessage($message)) {
				$this->logger->info('Highnet message was filtered away.');
				return;
			}
			$bucket = $this->buckets[$senderUUID] ?? null;
			if (!isset($bucket)) {
				$bucket = $this->buckets[$senderUUID] = new LeakyBucket(3, 2);
			}
			if ($bucket->getSize() > $this->highnetQueueSize) {
				$this->logger->info('Queue for {uuid} is over {bucket_size} - dropping message.', [
					'uuid' => $senderUUID,
					'bucket_size' => $this->highnetQueueSize,
				]);
				return;
			}
			$bucket->push($message);
			$nextMessage = $bucket->getNext();
			if (!isset($nextMessage)) {
				return;
			}
			$event = new HighnetEvent(message: $nextMessage);
			$this->eventManager->fireEvent($event);
		} catch (UnableToHydrateObject $e) {
			$this->logger->info('Invalid highnet-package received: {data}.', [
				'data' => $body,
				'exception' => $e,
			]);
		}
	}

	#[NCA\Event(name: HighnetEvent::EVENT_MASK, description: 'Handle Highnet messages')]
	public function handleMessage(HighnetEvent $event): void {
		$message = $event->message;
		$handler = $this->handlers[strtolower($message->channel)]??null;
		if (!isset($handler)) {
			$this->logger->info('No handler for Highnet channel {channel} found.', [
				'channel' => $message->channel,
			]);
			return;
		}
		$popup = $this->getInfoPopup($message);
		$msgs = Text::blobWrap(
			$message->message . ' [',
			$this->text->makeBlob('details', $popup, 'Message details'),
			']'
		);
		foreach ($msgs as $msg) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->setCharacter(new Character(
				name: $message->sender_name,
				id: $message->sender_uid,
				dimension: $message->dimension,
			));
			$rMsg->prependPath(new Source(
				type: 'highnet',
				name: strtolower($message->channel),
				label: $message->channel,
				dimension: $message->dimension,
			));
			$this->msgHub->handle($rMsg);
		}
	}

	/** Show information about the Highnet connection */
	#[NCA\HandlesCommand('highnet')]
	public function highnetInfoCommand(
		CmdContext $context,
	): void {
		if ($this->highnetEnabled === false) {
			$context->reply('Highnet is disabled on this bot.');
			return;
		}
		if (!isset($this->eventFeed->connection)) {
			$context->reply('Not connected to a any feed at all.');
			return;
		}
		if ($this->feedSupportsHighnet === false) {
			$context->reply('Not connected to a Highnet-capable feed.');
			return;
		}
		$numClients = "{$this->numClients} ".
				Text::pluralize('client', $this->numClients);
		$popup = "<header2>How to talk on Highnet<end>\n".
			"In order to talk to other bots on a Highnet channel, use\n".
			"<tab><highlight>{$this->highnetPrefix}&lt;channel&gt; &lt;message&gt;<end> - ".
			"for example <highlight>@chat Good morning, Rubi-Ka!<end>\n".
			"\n".
			"You can also use abbreviations of channels, as long as they are unique:\n".
			"<tab>@c, @ch, or @cha for @chat, but not @p, since that could be @pvp or @pvm.\n".
			"\n".
			"This, of course, only works if the channel you're talking to is routed to\n".
			"Highnet. By default, the main channel (org-chat or private-chat) of the bot are\n".
			"set up for relaying these messages, and silently discarding those that don't\n".
			"match a channel.\n".
			"\n".
			"<header2>Who can read me?<end>\n".
			'There ' . (($this->numClients === 1) ? 'is' : 'are') . ' '.
			"currently <highlight>{$numClients}<end> other than this bot connected to Highnet.\n".
			"Each of these clients can read your messages - if they have proper routing\n".
			"in place. How many people there are on each client is not exposed.\n".
			"\n".
			"<header2>What can I read?<end>\n".
			'The following Highnet channels are seen on this bot:';
		foreach ($this->channels as $channel) {
			$recs = $this->msgHub->getReceiversFor("highnet({$channel})");
			$visMsg = 'Not seen on this bot';
			if (count($recs)) {
				$visMsg = 'On ' . Text::enumerate(...$recs);
			}
			$popup .= "\n<tab><highlight>{$channel}<end>: {$visMsg}";
		}
		$popup .= "\n\n<header2>A word about security<end>\n".
			"Highnet uses an encrypted connection to the central server, but messages\n".
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
			'Be excellent to each other!';
		$channelMsg = 'Connected to the following Highnet channels: '.
			(count($this->channels)
			? (
				Text::enumerate(
					...Text::arraySprintf('<highlight>%s<end>', ...$this->channels)
				) . " with <highlight>{$numClients}<end> attached"
			)
			: '&lt;none&gt;');
		$msgs = Text::blobWrap(
			$channelMsg . ' [',
			$this->text->makeBlob('instructions', $popup, 'Instructions how to use Highnet'),
			']'
		);
		foreach ($msgs as $msg) {
			$context->reply($msg);
		}
	}

	/** Reset the whole Highnet configuration and setup default routes and colors */
	#[NCA\HandlesCommand('highnet reset')]
	public function highnetInitCommand(
		CmdContext $context,
		#[NCA\Str('reset', 'init')] string $action
	): void {
		$colors = $this->msgHub::$colors;

		/** @var list<int> */
		$colorIds = $colors->filter(static function (RouteHopColor $color): bool {
			return strncasecmp($color->hop, 'highnet', 7) === 0;
		})->pluck('id')->toList();

		/** @var list<int> */
		$formatIds = Source::$format->filter(static function (RouteHopFormat $format): bool {
			return strncasecmp($format->hop, 'highnet', 7) === 0;
		})->pluck('id')->toList();

		$routes = $this->msgHub->getRoutes();
		$deleteIds = [];
		foreach ($routes as $route) {
			$source = $route->getSource();
			$dest = $route->getDest();
			$isHighnetRoute = (strncasecmp($source, 'highnet', 7) === 0)
				|| (strncasecmp($dest, 'highnet', 7) === 0);
			if (!$isHighnetRoute) {
				continue;
			}
			$deleteIds []= $route->getID();
			$this->msgHub->deleteRouteID($route->getID());
		}
		$routes = [];
		$hops = ['web', isset($this->config->orgId) ? 'aoorg' : "aopriv({$this->config->main->character})"];

		foreach ($hops as $hop) {
			$route = new Route(
				source: 'highnet(*)',
				two_way: false,
				destination: $hop,
			);
			$routes []= $route;

			$route = new Route(
				source: $hop,
				two_way: false,
				destination: 'highnet',
			);
			$routes []= $route;
		}

		$rhf = new RouteHopFormat(
			hop: 'highnet',
			render: true,
			format: '@%s',
		);

		$rhc = new RouteHopColor(
			hop: 'highnet',
			tag_color: '00EFFF',
			text_color: '00BFFF',
		);

		$msgRoutes = [];
		$this->db->awaitBeginTransaction();
		try {
			$this->db->table(Route::getTable())
				->whereIn('id', $deleteIds)
				->delete();
			$this->db->table(RouteHopColor::getTable())
				->whereIn('id', $colorIds)
				->delete();
			$this->db->table(RouteHopFormat::getTable())
				->whereIn('id', $formatIds)
				->delete();
			$this->db->insert($rhc);
			$this->db->insert($rhf);
			foreach ($routes as $route) {
				$this->db->insert($route);
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

		$context->reply('Routing for Highnet initialized.');
	}

	/** Show the currently active filters */
	#[NCA\HandlesCommand(self::FILTERS)]
	public function highnetListFilters(
		CmdContext $context,
		#[NCA\Str('filters', 'filter')] string $action
	): void {
		$this->cleanExpiredFilters();
		$this->reloadFilters();
		if ($this->filters->isEmpty()) {
			$context->reply('No Highnet filters are currently active.');
			return;
		}
		$blob = "<header2>Currently active filters<end>\n".
			$this->filters
				->map(Closure::fromCallable($this->renderFilter(...)))
				->join("\n");
		$msg = $this->text->makeBlob(
			'Highnet filters (' . $this->filters->count() . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Permanently block Highnet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function highnetAddPermanentUserFilters(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		#[NCA\Str('permanent')] string $permanent,
		#[NCA\StrChoice('bot', 'sender')] string $where,
		PCharacter $name,
		int $dimension,
	): void {
		$this->highnetAddUserFilter(
			context: $context,
			where: $where,
			name: $name,
			duration: null,
			dimension: $dimension,
		);
	}

	/** Temporarily block Highnet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function highnetAddTemporaryUserFilters(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		PDuration $duration,
		#[NCA\StrChoice('bot', 'sender')] string $where,
		PCharacter $name,
		int $dimension,
	): void {
		$this->highnetAddUserFilter(
			context: $context,
			where: $where,
			name: $name,
			duration: $duration,
			dimension: $dimension,
		);
	}

	/** Permanently block Highnet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function highnetAddPermanentChannelFilters(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		#[NCA\Str('permanent')] string $permanent,
		#[NCA\Str('channel')] string $where,
		PWord $channel,
		?int $dimension,
	): void {
		$this->highnetAddChannelFilter(
			context: $context,
			duration: null,
			channel: $channel,
			dimension: $dimension,
		);
	}

	/** Temporarily block Highnet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function highnetAddTemporaryChannelFilters(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		PDuration $duration,
		#[NCA\Str('channel')] string $where,
		PWord $channel,
		?int $dimension,
	): void {
		$this->highnetAddChannelFilter(
			context: $context,
			duration: $duration,
			channel: $channel,
			dimension: $dimension,
		);
	}

	/** Permanently block Highnet-messages */
	#[NCA\HandlesCommand(self::PERM_FILTERS)]
	public function highnetAddPermanentDimensionFilter(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		#[NCA\Str('permanent')] string $permanent,
		#[NCA\Str('dimension')] string $where,
		int $dimension,
	): void {
		$this->highnetAddDimensionFilter($context, null, $dimension);
	}

	/** Temporarily block Highnet-messages */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function highnetAddTemporaryDimensionFilter(
		CmdContext $context,
		#[NCA\Str('filter', 'filters')] string $action,
		PDuration $duration,
		#[NCA\Str('dimension')] string $where,
		int $dimension,
	): void {
		$this->highnetAddDimensionFilter($context, $duration, $dimension);
	}

	/** Delete a Highnet filter */
	#[NCA\HandlesCommand(self::TEMP_FILTERS)]
	public function highnetDeleteFilter(
		CmdContext $context,
		#[NCA\Str('filter')] string $filter,
		PRemove $action,
		PUuid $id
	): void {
		$id = $id();

		/** @var ?FilterEntry */
		$filter = $this->db
			->table(FilterEntry::getTable())
			->where('id', $id)
			->asObj(FilterEntry::class)
			->first();
		if (!isset($filter)) {
			$context->reply("Highnet filter <highlight>{$id}<end> does not exist.");
			return;
		}
		if (!isset($filter->expires)) {
			$hasPermPrivs = $this->cmdManager->couldRunCommand(
				$context,
				'highnet filter permanent dimension 5'
			);
			if (!$hasPermPrivs) {
				$context->reply("You don't have the required rights to delete permanent filters.");
				return;
			}
		}
		if ($this->db->table(FilterEntry::getTable())->delete($filter->id) === 0) {
			$context->reply('There was an unknown error deleting this filter.');
			return;
		}
		$this->reloadFilters();
		$context->reply(
			"Filter <highlight>#{$filter->id}<end> ".
			'(' . $this->getFilterDescr($filter) . ') '.
			'successfully deleted.'
		);
	}

	public function dimensionToName(int $dimension): string {
		$map = [
			4 => 'Test-Server',
			5 => 'Rubi-Ka',
			6 => 'RK19',
		];
		return $map[$dimension] ?? 'Unknown server';
	}

	public function getInfoPopup(Message $message): string {
		$blob = '<header2>Information about this message<end>';
		$blob .= "\n<tab>Sent: <highlight>" . Util::date($message->sent) . '<end>';

		$blockTempLink = Text::makeChatcmd(
			'block 15min',
			"/tell <myname> highnet filter 15m dimension {$message->dimension}"
		);
		$blockPermLink = Text::makeChatcmd(
			'block',
			"/tell <myname> highnet filter permanent dimension {$message->dimension}"
		);
		$blob .= "\n<tab>Dimension: ".
			"<highlight>{$message->dimension} ".
			'(' . $this->dimensionToName($message->dimension) . ')'.
			"<end> [{$blockTempLink}] [{$blockPermLink}]";

		$blockTempLink = Text::makeChatcmd(
			'block 15min',
			"/tell <myname> highnet filter 15m channel {$message->channel}"
		);
		$blockPermLink = Text::makeChatcmd(
			'block',
			"/tell <myname> highnet filter permanent channel {$message->channel}"
		);
		$blob .= "\n<tab>Channel: ".
			'<highlight>' . ($this->getPrettyChannelName($message->channel) ?? $message->channel) .'<end> '.
			"[{$blockTempLink}] [{$blockPermLink}]";

		$senderName = $message->sender_name;
		if (isset($message->sender_uid)) {
			$senderName .= " (UID {$message->sender_uid})";
		}
		$blockTempLink = Text::makeChatcmd(
			'block 15min',
			"/tell <myname> highnet filter 15m sender {$message->sender_name} {$message->dimension}"
		);
		$blockPermLink = Text::makeChatcmd(
			'block',
			"/tell <myname> highnet filter permanent sender {$message->sender_name} {$message->dimension}"
		);
		$blob .= "\n<tab>Sender: <highlight>{$senderName}<end> [{$blockTempLink}] [{$blockPermLink}]";

		$botName = $message->bot_name;
		if (isset($message->bot_uid)) {
			$botName .= " (UID {$message->bot_uid})";
		}
		$blockTempLink = Text::makeChatcmd(
			'block 15min',
			"/tell <myname> highnet filter 15m bot {$message->bot_name} {$message->dimension}"
		);
		$blockPermLink = Text::makeChatcmd(
			'block',
			"/tell <myname> highnet filter permanent bot {$message->bot_name} {$message->dimension}"
		);
		$blob .= "\n<tab>Via Bot: <highlight>{$botName}<end> [{$blockTempLink}] [{$blockPermLink}]";

		$filtersCmd = Text::makeChatcmd(
			'<symbol>highnet filters',
			'/tell <myname> highnet filters'
		);
		$highnetCmd = Text::makeChatcmd(
			'<symbol>highnet',
			'/tell <myname> highnet'
		);
		$blob .= "\n\n".
			"<i>Blocks on your bot have no impact on other bots.</i>\n".
			"<i>Take a moment to consider before permanently blocking players or bots;</i>\n".
			"<i>most problems resolve themselves within 15 minutes once tempers have cooled down.</i>\n\n".
			"<i>To list all currently active filters, use {$filtersCmd}.</i>\n".
			"<i>For information about Highnet, use {$highnetCmd}.</i>";

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
			$parts []= 'Dimension=' . $this->dimensionToName($entry->dimension).
				" ({$entry->dimension})";
		}
		if (isset($entry->channel)) {
			$displayChannel = $this->getPrettyChannelName($entry->channel) ?? $entry->channel;
			$parts []= "Channel={$displayChannel}";
		}
		return implode(' AND ', $parts);
	}

	/**
	 * Route message $message that come in via routing event $event
	 * to the Highnet channel $channel
	 */
	public function handleIncoming(RoutableEvent $event, string $channel, string $message): bool {
		if (!$this->highnetEnabled) {
			$this->logger->info('Highnetr disabled - dropping message');
			return false;
		}
		if (!isset($this->eventFeed->connection)) {
			$this->logger->info('No event feed connected - dropping Highnet message');
			return false;
		}
		$sourceHop = $this->getSourceHop($event);
		if (!isset($sourceHop)) {
			$this->logger->info('No source-hop found in message to Highnet - dropping');
			return false;
		}
		if (!$this->msgHub->hasRouteFromTo("highnet({$channel})", $sourceHop)) {
			$this->logger->info('No route from {target} {source} - not routing to highnet', [
				'target' => "highnet({$channel})",
				'source' => $sourceHop,
			]);
			return false;
		}
		EventLoop::queue($this->continueHandleIncoming(...), $event, $channel, $message);
		return true;
	}

	private function continueHandleIncoming(RoutableEvent $event, string $channel, string $message): void {
		$botUid = $this->chatBot->char?->id;
		if (!isset($botUid)) {
			return;
		}
		$character = $event->getCharacter();
		if (isset($character) && !isset($character->id)) {
			$character = clone $character;
			$character->id = $this->chatBot->getUid($character->name);
		}
		$message = new Message(
			dimension: $character?->dimension ?? $this->config->main->dimension,
			bot_uid: $botUid,
			bot_name: $this->config->main->character,
			sender_uid: $character?->id,
			sender_name: $character?->name ?? $this->config->main->character,
			main: $character ? $this->altsCtrl->getMainOf($character->name) : null,
			nick: $character ? $this->nickCtrl->getNickname($character->name) : null,
			sent: time(),
			channel: $channel,
			message: $message,
		);
		$serializer = new ObjectMapperUsingReflection();
		$hwBody = $serializer->serializeObject($message);
		if (!is_array($hwBody)) {
			$this->logger->warning('Cannot serialize data for Highnet - dropping', [
				'message' => $message,
			]);
			return;
		}
		$packet = new Highway\Out\Message(room: 'highnet', body: $hwBody);
		$this->logger->debug('Sending message to Highnet: {data}', [
			'data' => $hwBody,
		]);
		$this->eventFeed->connection?->send($packet);

		if (!$this->highnetRouteInternally) {
			$this->logger->info('Internal Highnet routing disabled.');
			return;
		}
		$missingReceivers = $this->getInternalRoutingReceivers($event, $channel);
		if (count($missingReceivers)) {
			$this->logger->info('Routing Highnet message internally to {targets}', [
				'targets' => $missingReceivers,
			]);
		}

		$rMsg = $this->nnMessageToRoutableMessage($message);
		foreach ($missingReceivers as $missingReceiver) {
			$handler = $this->msgHub->getReceiver($missingReceiver);
			if (isset($handler)) {
				$handler->receive($rMsg, $missingReceiver);
			}
		}
	}

	/** Create a RoutableMessage from a Highnet message for internal routing only */
	private function nnMessageToRoutableMessage(Message $message): RoutableMessage {
		$rMsg = new RoutableMessage($message->message);
		$rMsg->setCharacter(new Character(
			name: $message->sender_name,
			id: $message->sender_uid,
			dimension: $message->dimension,
		));
		$rMsg->prependPath(new Source(
			type: 'highnet',
			name: strtolower($message->channel),
			label: $message->channel,
			dimension: $message->dimension,
		));
		return $rMsg;
	}

	private function getSourceHop(RoutableEvent $event): ?string {
		$senderHops = $event->getPath();
		if (!count($senderHops)) {
			return null;
		}
		$sourceHop = $senderHops[0]->type . '(' . $senderHops[0]->name . ')';
		$emitter = $this->msgHub->getEmitter($sourceHop);
		if (isset($emitter) && !str_contains($emitter->getChannelName(), '(')) {
			return $emitter->getChannelName();
		}
		return $sourceHop;
	}

	/**
	 * Get a list of routing destinations that would receive Highnet messages
	 * from $channel, but not messages from the origin of $event.
	 *
	 * @return list<string>
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
		$highnetReceivers = $this->msgHub->getReceiversFor("highnet({$channel})");
		return array_values(
			array_filter(
				$highnetReceivers,
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
		$update = count($this->handlers);
		foreach ($this->channels as $channel) {
			if (isset($this->handlers[strtolower($channel)])) {
				continue;
			}
			$handler = new HighnetChannel(strtolower($channel));
			Registry::injectDependencies($handler);
			$this->msgHub
				->registerMessageEmitter($handler)
				->registerMessageReceiver($handler);
			$this->handlers[strtolower($channel)] = $handler;
			if ($update) {
				$this->logger->notice('New Highnet-channel {channel} registered.', [
					'channel' => $channel,
				]);
			}
		}
		if (!isset($this->receiverHandler)) {
			$handler = new HighnetReceiver();
			Registry::injectDependencies($handler);
			$this->msgHub->registerMessageReceiver($handler);
			$this->receiverHandler = $handler;
		}
		$lowerChannels = array_map('strtolower', $this->channels);
		$currentChannels = array_keys($this->handlers);
		foreach ($currentChannels as $channel) {
			if (!in_array($channel, $lowerChannels, true)) {
				unset($this->handlers[$channel]);
				$routes = $this->msgHub->getRoutes();
				foreach ($routes as $route) {
					if (
						$route->getSource() === "highnet({$channel})"
						|| $route->getDest() === "highnet({$channel})"
					) {
						$this->msgHub->deleteRouteID($route->getID());
						$this->db->table(Route::getTable())->delete($route->getID());
					}
				}
				$this->msgHub->unregisterMessageEmitter("highnet({$channel})");
				$this->msgHub->unregisterMessageReceiver("highnet({$channel})");
				$this->logger->notice('Highnet-channel {channel} removed.', [
					'channel' => $channel,
				]);
			}
		}
	}

	private function unregisterChannelHandlers(): void {
		foreach ($this->handlers as $channel => $handler) {
			$this->msgHub
				->unregisterMessageEmitter($handler->getChannelName())
				->unregisterMessageReceiver($handler->getChannelName());
		}
		if (isset($this->receiverHandler)) {
			$this->msgHub->unregisterMessageReceiver('highnet');
			$this->receiverHandler = null;
		}
	}

	private function highnetAddDimensionFilter(
		CmdContext $context,
		?PDuration $duration,
		int $dimension,
	): void {
		$entry = new FilterEntry(
			creator: $context->char->name,
			dimension: $dimension,
		);
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3_600 * 24) {
				$context->reply('You cannot issue a temporary block for over 24h');
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		$this->db->insert($entry);
		$this->reloadFilters();
		$context->reply('Filter ' . $this->getFilterDescr($entry) . ' added.');
	}

	private function renderFilter(FilterEntry $entry): string {
		$line = $this->getFilterDescr($entry);
		if (isset($entry->expires) && $entry->expires > time()) {
			$remaining = $entry->expires - time();
			$line .= ' - expires in ' . Util::unixtimeToReadable($remaining, false);
		}
		$deleteLink = Text::makeChatcmd(
			'del',
			"/tell <myname> highnet filter del {$entry->id}"
		);
		return "<tab>* [{$deleteLink}] <highlight>#{$entry->id}<end> {$line}";
	}

	private function isWantedMessage(Message $message): bool {
		return $this->filters->first(static function (FilterEntry $entry) use ($message): bool {
			return $entry->matches($message);
		}) === null;
	}

	private function highnetAddUserFilter(
		CmdContext $context,
		?PDuration $duration,
		string $where,
		PCharacter $name,
		int $dimension,
	): void {
		$entry = new FilterEntry(
			creator: $context->char->name,
			dimension: $dimension,
		);
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3_600 * 24) {
				$context->reply('You cannot issue a temporary block for over 24h');
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		$uid = null;
		if ($dimension === $this->config->main->dimension) {
			$uid = $this->chatBot->getUid($name());
			if ($uid === null) {
				$context->reply("The character {$name} does not exist");
				return;
			}
		}
		if ($where === 'bot') {
			$entry->bot_name = $name();
			$entry->bot_uid = $uid;
		} else {
			$entry->sender_name = $name();
			$entry->sender_uid = $uid;
		}
		$this->db->insert($entry);
		$this->reloadFilters();
		$context->reply('Filter ' . $this->getFilterDescr($entry) . ' added.');
	}

	private function highnetAddChannelFilter(
		CmdContext $context,
		?PDuration $duration,
		PWord $channel,
		?int $dimension,
	): void {
		$entry = new FilterEntry(
			creator: $context->char->name,
			dimension: $dimension,
		);
		if (isset($duration)) {
			$secDuration = $duration->toSecs();
			if ($secDuration > 3_600 * 24) {
				$context->reply('You cannot issue a temporary block for over 24h');
				return;
			}
			$entry->expires = time() + $secDuration;
		}
		if ($this->getPrettyChannelName($channel()) === null) {
			$context->reply("The channel {$channel} does not exist.");
			return;
		}
		$entry->channel = strtolower($channel());
		$this->db->insert($entry);
		$this->reloadFilters();
		$context->reply('Filter ' . $this->getFilterDescr($entry) . ' added.');
	}

	/** Check if 2 routing destinations are identical */
	private function routeDestsMatch(string $route1, string $route2): bool {
		if (!str_contains($route1, '(')) {
			$route1 .= '(*)';
		}
		if (!str_contains($route2, '(')) {
			$route2 .= '(*)';
		}
		return fnmatch($route1, $route2, \FNM_CASEFOLD)
			|| fnmatch($route2, $route1, \FNM_CASEFOLD);
	}
}
