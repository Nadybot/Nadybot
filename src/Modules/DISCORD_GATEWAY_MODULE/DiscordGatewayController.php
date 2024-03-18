<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Amp\Future\await;
use function Amp\{async, delay};
use function Safe\{json_decode, json_encode, preg_match};

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, HttpException};
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Rfc6455Connector, WebsocketConnectException, WebsocketConnection, WebsocketHandshake};
use Amp\Websocket\{WebsocketCloseCode, WebsocketClosedException, WebsocketCount};
use Illuminate\Support\{Collection, ItemNotFoundException};
use Nadybot\Core\Filesystem;
use Nadybot\Core\Modules\DISCORD\{
	DiscordAPIClient,
	DiscordChannel,
	DiscordChannelInvite,
	DiscordController,
	DiscordEmbed,
	DiscordException,
	DiscordMessageIn,
	DiscordScheduledEvent,
	DiscordUser,
};
use Nadybot\Core\{
	Attributes as NCA,
	Channels\DiscordChannel as RoutedChannel,
	Channels\DiscordMsg,
	CmdContext,
	CommandManager,
	DB,
	EventManager,
	MessageHub,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	Registry,
	Routing\Character,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Util,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	Activity,
	CloseEvents,
	Guild,
	GuildMemberChunk,
	IdentifyPacket,
	Intent,
	Opcode,
	Payload,
	RequestGuildMembers,
	ResumePacket,
	UpdateStatus,
	VoiceState,
};
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Modules\WEBSERVER_MODULE\StatsController;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionClassConstant;
use Revolt\EventLoop;
use Safe\Exceptions\JsonException;
use stdClass;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\ProvidesEvent("discordmsg"),
	NCA\ProvidesEvent("discordpriv"),
	NCA\ProvidesEvent("discord(0)"),
	NCA\ProvidesEvent("discord(7)"),
	NCA\ProvidesEvent("discord(9)"),
	NCA\ProvidesEvent("discord(10)"),
	NCA\ProvidesEvent("discord(11)"),
	NCA\ProvidesEvent("discord(ready)"),
	NCA\ProvidesEvent("discord(resumed)"),
	NCA\ProvidesEvent("discord(guild_create)"),
	NCA\ProvidesEvent("discord(guild_delete)"),
	NCA\ProvidesEvent("discord(guild_update)"),
	NCA\ProvidesEvent("discord(guild_update_delete)"),
	NCA\ProvidesEvent("discord(guild_member_add)"),
	NCA\ProvidesEvent("discord(guild_members_chunk)"),
	NCA\ProvidesEvent("discord(guild_role_create)"),
	NCA\ProvidesEvent("discord(guild_role_update)"),
	NCA\ProvidesEvent("discord(guild_role_update_delete)"),
	NCA\ProvidesEvent("discord(guild_scheduled_event_update)"),
	NCA\ProvidesEvent("discord(guild_scheduled_event_create)"),
	NCA\ProvidesEvent("discord(guild_scheduled_event_delete)"),
	NCA\ProvidesEvent("discord(guild_scheduled_user_add)"),
	NCA\ProvidesEvent("discord(guild_scheduled_user_remove)"),
	NCA\ProvidesEvent("discord(interaction_create)"),
	NCA\ProvidesEvent("discord(message_create)"),
	NCA\ProvidesEvent("discord(message_update)"),
	NCA\ProvidesEvent("discord(message_delete)"),
	NCA\ProvidesEvent("discord(message_delete_bulk)"),
	NCA\ProvidesEvent("discord(channel_create)"),
	NCA\ProvidesEvent("discord(channel_update)"),
	NCA\ProvidesEvent("discord(channel_delete)"),
	NCA\ProvidesEvent("discord(channel_pins_update)"),
	NCA\ProvidesEvent("discord(voice_state_update)"),
	NCA\ProvidesEvent(DiscordVoiceJoinEvent::EVENT_MASK),
	NCA\ProvidesEvent(DiscordVoiceLeaveEvent::EVENT_MASK),

	NCA\EmitsMessages("discord", "event-create"),
	NCA\EmitsMessages("discord", "event-delete"),
	NCA\EmitsMessages("discord", "event-start"),
	NCA\EmitsMessages("discord", "event-end"),

	NCA\DefineCommand(
		command: "discord",
		accessLevel: "member",
		description: "Check the current Discord connection",
	),
	NCA\DefineCommand(
		command: "discord connect/disconnect",
		accessLevel: "mod",
		description: "Connect or disconnect the bot from Discord",
	),
	NCA\DefineCommand(
		command: "discord create invite for yourself",
		accessLevel: "member",
		description: "Create a Discord invite link",
	),
	NCA\DefineCommand(
		command: "discord see invites",
		accessLevel: "mod",
		description: "See all invites on all Discord servers",
	),
	NCA\DefineCommand(
		command: "discord leave server",
		accessLevel: "mod",
		description: "Let the bot leave a Discord server",
	),
	NCA\DefineCommand(
		command: "discord show events",
		accessLevel: "member",
		description: "Show the scheduled Discord events",
	),
]
class DiscordGatewayController extends ModuleInstance {
	public const DB_TABLE = "discord_invite_<myname>";
	public const EMOJI_TABLE = "discord_emoji_<myname>";
	public const RENAME_OFF = "Off";

	/** Game the bot is shown to play on Discord */
	#[NCA\Setting\Text]
	public string $discordActivityName = "Anarchy Online";

	/** Rename the Discord name to match the AO name for registered users */
	#[NCA\Setting\Text(options: [
		self::RENAME_OFF,
		"{name}",
		"[{org}] {name}",
		"{name} [{org}]",
	])]
	public string $discordRenameUsers = "{name}";

	/** ID of the Discord role to automatically assign to registered users */
	#[NCA\Setting\Text]
	public string $discordAssignRole = "";

	/** @var array<string,DiscordChannelInvite[]> */
	public array $invites = [];

	protected ?int $lastSequenceNumber = null;
	protected ?WebsocketConnection $client = null;
	protected bool $mustReconnect = false;
	protected ?string $reconnectUrl = null;
	protected int $lastHeartbeat = 0;
	protected int $heartbeatInterval = 40;
	protected int $reconnectDelay = 5;
	protected ?DiscordUser $me = null;
	protected ?string $sessionId = null;

	/** @var array<string,Guild> */
	protected array $guilds = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private RelayController $relayController;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordGatewayCommandHandler $discordGatewayCommandHandler;

	#[NCA\Inject]
	private DiscordSlashCommandController $discordSlashCommandController;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private DiscordController $discordController;

	#[NCA\Inject]
	private StatsController $statsController;
	private DiscordPacketsStats $inStats;
	private DiscordPacketsStats $outStats;

	/** @var array<string,bool> */
	private array $noManageInviteRights = [];

	private int $lastPackets = 0;

	public function isMe(string $id): bool {
		return isset($this->me) && $this->me->id === $id;
	}

	public function getID(): ?string {
		return $this->me->id ?? null;
	}

	/**
	 * Get a list of all guilds this bot is a member of
	 *
	 * @return array<string,Guild>
	 */
	public function getGuilds(): array {
		return $this->guilds;
	}

	/** Check if the bot is connected and authenticated to the Discord gateway */
	public function isConnected(): bool {
		return isset($this->sessionId) && strlen($this->sessionId) > 0;
	}

	/** Search for a Discord channel we are subscribed to by channel ID */
	public function getChannel(string $channelId): ?DiscordChannel {
		foreach ($this->guilds as $guild) {
			foreach ($guild->channels as $channel) {
				if ($channel->id === $channelId) {
					$channel->guild_id = $guild->id;
					return $channel;
				}
			}
		}
		return null;
	}

	/** Search for a Discord channel we are subscribed to by channel ID */
	public function getChannelGuild(?string $channelId): ?Guild {
		if (!isset($channelId)) {
			return null;
		}
		foreach ($this->guilds as $guild) {
			foreach ($guild->channels as $channel) {
				if ($channel->id === $channelId) {
					return $guild;
				}
			}
		}
		return null;
	}

	/** Lookup a channel by its ID and call a callback with the resolved channel */
	public function lookupChannel(string $channelId): ?DiscordChannel {
		$channel = $this->getChannel($channelId);
		if (isset($channel)) {
			return $channel;
		}
		$channel = $this->discordAPIClient->getChannel($channelId);
		$guildId = $channel->guild_id;
		if (!isset($guildId) || !isset($this->guilds[$guildId])) {
			return null;
		}
		$this->guilds[$guildId]->channels []= $channel;
		return $channel;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->inStats = new DiscordPacketsStats("in");
		$this->outStats = new DiscordPacketsStats("out");
		$this->statsController->registerProvider($this->inStats, "discord");
		$this->statsController->registerProvider($this->outStats, "discord");
	}

	#[NCA\SettingChangeHandler('discord_activity_name')]
	public function updatePresence(string $settingName, string $oldValue, string $newValue): void {
		if (!isset($this->client)) {
			EventLoop::delay(1, function (string $token) use ($settingName, $oldValue, $newValue): void {
				$this->updatePresence($settingName, $oldValue, $newValue);
			});
			return;
		}
		$packet = new Payload();
		$packet->op = Opcode::PRESENCE_UPDATE;
		$packet->d = new UpdateStatus();
		$activity = new Activity();
		$activity->name = $newValue;
		if (strlen($newValue)) {
			$packet->d->activities = [$activity];
		} else {
			$packet->d->activities = [];
		}
		async($this->client->sendText(...), json_encode($packet));
	}

	/** Start, stop or restart the websocket connection if the token changes */
	#[NCA\SettingChangeHandler('discord_bot_token')]
	public function tokenChanged(string $settingName, string $oldValue, string $newValue): void {
		if ($oldValue !== "" && $oldValue !== 'off' && isset($this->client)) {
			$this->logger->notice("Closing Discord gateway connection.");
			$this->client->close();
		}
		if ($newValue !== "" && $newValue !== 'off') {
			async($this->connect(...));
		}
	}

	#[NCA\Event(
		name: "connect",
		description: "Connects to the Discord server"
	)]
	public function connectToDiscordgateway(): void {
		$this->connect();
	}

	public function connect(): void {
		$botToken = $this->discordController->discordBotToken;
		if (empty($botToken) || $botToken === 'off') {
			return;
		}
		async($this->connectToGateway(...));
	}

	public function processWebsocketWrite(): void {
		$this->outStats->inc();
	}

	public function processWebsocketMessage(string $message): void {
		$this->logger->debug("Received discord message", ["message" => $message]);
		$payload = new Payload();
		try {
			if ($message === '') {
				throw new JsonException("null message received.");
			}
			$payload->fromJSON(json_decode($message));
		} catch (JsonException $e) {
			$this->logger->error("Invalid JSON data received from Discord: {error}", [
				"error" => $e->getMessage(),
				"data" => $message,
				"exception" => $e,
			]);
			if (isset($this->client)) {
				$this->client->close(4002);
			}
			return;
		}
		$this->logger->debug("Packet received", ["packet" => $payload]);
		$opcodeToName = [
			 0 => "Dispatch",
			 1 => "Heartbeat",
			 2 => "Identify",
			 3 => "Presence Update",
			 4 => "Voice State Update",
			 6 => "Resume",
			 7 => "Reconnect",
			 8 => "Request Guild Members",
			 9 => "Invalid Session",
			10 => "Hello",
			11 => "Heartbeat ACK",
		];
		$this->logger->info("Received packet opcode {opcode} ({opcodeName})", [
			"opcode" => $payload->op,
			"opcodeName" => $opcodeToName[$payload->op] ?? "unknown",
			"data" => $payload->d,
		]);
		if (isset($payload->s)) {
			$this->lastSequenceNumber = $payload->s;
		}
		$eventObj = new DiscordGatewayEvent(
			type: "discord({$payload->op})",
			message: $message,
			payload: $payload,
		);
		$this->eventManager->fireEvent($eventObj);
	}

	#[NCA\Event(
		name: "discord(10)",
		description: "Authorize to discord gateway",
		defaultStatus: 1
	)]
	public function processGatewayHello(DiscordGatewayEvent $event): void {
		$payload = $event->payload;

		/** @var stdClass $payload->d */
		$this->heartbeatInterval = intdiv($payload->d->heartbeat_interval, 1000);
		EventLoop::repeat(
			$this->heartbeatInterval,
			fn (string $watcherId) => $this->sendWebsocketHeartbeat($watcherId)
		);
		$this->logger->info("Setting Discord heartbeat interval to ".$this->heartbeatInterval."sec");
		$this->lastHeartbeat = time();

		if ($this->sessionId !== null && $this->lastSequenceNumber !== null) {
			$this->sendResume();
		} else {
			$this->sendIdentify();
		}
	}

	#[NCA\Event(
		name: "discord(0)",
		description: "Handle discord gateway intents",
		defaultStatus: 1
	)]
	public function processGatewayEvents(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		if ($payload->t === null) {
			return;
		}
		$newEvent = new DiscordGatewayEvent(
			payload: $payload,
			type: strtolower("discord({$payload->t})"),
			message: null,
		);
		$this->logger->info("New event: discord({event})", ["event" => $payload->t]);
		$this->eventManager->fireEvent($newEvent);
	}

	#[NCA\Event(
		name: "discord(7)",
		description: "Reconnect to discord gateway if requested",
		defaultStatus: 1
	)]
	public function processGatewayReconnectRequest(DiscordGatewayEvent $event): void {
		$this->logger->info("Discord Gateway requests reconnect");
		$this->reconnectDelay = 1;
		if (isset($this->client)) {
			$this->client->close(WebsocketCloseCode::NORMAL_CLOSE);
		}
	}

	#[NCA\Event(
		name: "discord(9)",
		description: "Handle invalid session answers",
		defaultStatus: 1
	)]
	public function processGatewayInvalidSession(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		if ($payload->d === true) {
			$this->logger->info("Session invalid, trying to resume");
			$this->sendResume();
			return;
		}
		$this->logger->info("Session invalid, trying to start new one");
		$this->sendIdentify();
	}

	#[NCA\Event(
		name: "discord(guild_members_chunk)",
		description: "Handle discord server members",
		defaultStatus: 1
	)]
	public function processDiscordMembersChunk(DiscordGatewayEvent $event): void {
		if (!isset($event->payload->d) || !is_object($event->payload->d)) {
			return;
		}
		$chunk = new GuildMemberChunk();
		$chunk->fromJSON($event->payload->d);
		$this->logger->debug("Processing incoming discord members chunk", [
			"message" => $chunk,
		]);
		$oldMembers = [];
		$guild = $this->guilds[$chunk->guild_id] ?? null;
		if (!isset($guild)) {
			return;
		}
		$guild->members ??= [];
		foreach ($guild->members as $member) {
			if (!isset($member->user)) {
				continue;
			}
			$oldMembers[$member->user->id] = $member;
		}
		foreach ($chunk->members as $member) {
			if (!isset($member->user) || isset($oldMembers[$member->user->id])) {
				continue;
			}
			$this->discordAPIClient->cacheGuildMember($guild->id, $member);
			$guild->members []= $member;
		}
	}

	#[NCA\Event(
		name: "discord(message_create)",
		description: "Handle discord gateway messages",
		defaultStatus: 1
	)]
	public function processDiscordMessage(DiscordGatewayEvent $event): void {
		$message = new DiscordMessageIn();

		/** @var stdClass $event->payload->d */
		$message->fromJSON($event->payload->d);
		$this->logger->debug("Processing incoming discord message", [
			"message" => $message,
		]);
		if (!isset($message->author)) {
			return;
		}
		if ($this->isMe($message->author->id)) {
			return;
		}

		$this->discordAPIClient->cacheUser($message->author);
		$name = $message->author->username . "#" . $message->author->discriminator;
		$member = null;
		if (isset($message->member)) {
			$member = $message->member;
			$member->user ??= $message->author;
			if (isset($message->guild_id)) {
				$this->discordAPIClient->cacheGuildMember($message->guild_id, $member);
			}
			if (isset($message->member->nick) && strlen($message->member->nick)) {
				$name = $message->member->nick;
			}
		}
		$channel = $this->getChannel($message->channel_id);
		$channelName = $channel ? ($channel->name??"DM") : "thread";
		if (isset($message->guild_id)) {
			$this->chatBot->logChat("Discord:{$channelName}", $name, $message->content);
		} else {
			$this->chatBot->logChat("Inc. Discord Msg.", $name, $message->content);
		}

		$text = DiscordRelayController::formatMessage($message->content);
		foreach (($message->embeds??[]) as $embed) {
			if (strlen($text)) {
				$text .= "\n";
			}
			$text .= $this->embedToAOML($embed);
		}
		if (empty($text)) {
			return;
		}

		$event = new DiscordMessageEvent(
			message: $message->content,
			sender: $name,
			type: isset($message->guild_id) ? "discordpriv" : "discordmsg",
			discord_message: $message,
			channel: $message->channel_id,
		);
		$this->eventManager->fireEvent($event);

		$aoMessage = $this->resolveDiscordMentions($message->guild_id??null, $text);
		$rMessage = new RoutableMessage($aoMessage);
		if (isset($message->guild_id) && strlen($message->guild_id)) {
			// $source = new Source(Source::DISCORD_GUILD, $this->guilds[(string)$message->guild_id]->name??null);
			// $rMessage->appendPath($source);
			$source = new Source(Source::DISCORD_PRIV, $channelName, null, (int)$message->guild_id);
			$rMessage->appendPath($source);
		} else {
			$source = new Source(Source::DISCORD_MSG, $name);
			$rMessage->prependPath($source);
		}
		if (isset($member)) {
			$name = $this->discordGatewayCommandHandler->getNameForDiscordId($member->user->id??"") ?? $name;
		}

		$senderDisplayName = Safe::pregReplace("/([\x{0450}-\x{fffff}])/u", "", $name);
		$senderDisplayName = trim($senderDisplayName);
		$rMessage->setCharacter(new Character($senderDisplayName));
		$this->messageHub->handle($rMessage);
	}

	public function embedToAOML(DiscordEmbed $embed): string {
		$blob = "";
		if (isset($embed->description) && strlen($embed->description)) {
			$blob .= DiscordRelayController::formatMessage($embed->description) . "\n\n";
		}
		foreach ($embed->fields??[] as $field) {
			$blob .= "<header2>".
				DiscordRelayController::formatMessage($field->name).
				"<end>\n".
				DiscordRelayController::formatMessage($field->value) . "\n\n";
		}
		if (!empty($embed->footer) && !empty($embed->footer->text)) {
			$blob .= "<i>".
				DiscordRelayController::formatMessage($embed->footer->text).
				"</i>";
		}
		if (isset($embed->title) && strlen($embed->title)) {
			if (isset($embed->url) && strlen($embed->url)) {
				$blob = "Details <a href='chatcmd:///start {$embed->url}'>here</a>\n\n".
					$blob;
			}
			$msg = ((array)$this->text->makeBlob(
				DiscordRelayController::formatMessage($embed->title),
				$blob
			))[0];
		} else {
			$msg = $blob;
		}
		return $msg;
	}

	/** Recursively resolve all mentions in $message and then return it */
	public function resolveDiscordMentions(?string $guildId, string $message): string {
		while (count($matches = Safe::pregMatch("/(?:<|&lt;)@!?(\d+)(?:>|&gt;)/", $message))) {
			if (!isset($matches[1])) {
				return $message;
			}
			$niceName = $this->discordGatewayCommandHandler->getNameForDiscordId($matches[1]);
			if (isset($niceName)) {
				/** @var string */
				$message = Safe::pregReplace("/(?:<|&lt;)@!?" . preg_quote($matches[1], "/") . "(?:>|&gt;)/", "@{$niceName}", $message);
				continue;
			}
			if (isset($guildId)) {
				$member = $this->discordAPIClient->getGuildMember($guildId, $matches[1]);

				/** @var string */
				$message = Safe::pregReplace("/(?:<|&lt;)@!?" . ($member->user->id??"") . "(?:>|&gt;)/", "@" . $member->getName(), $message);
				continue;
			}
			$user = $this->discordAPIClient->getUser($matches[1]);

			/** @var string */
			$message = Safe::pregReplace("/(?:<|&lt;)@!?" . $user->id . "(?:>|&gt;)/", "@{$user->username}", $message);
		}
		return $message;
	}

	#[NCA\Event(
		name: "setting(discord_custom_emojis)",
		description: "(Un)register emojis from discord",
	)]
	public function emojiSettingsChanged(): void {
		foreach ($this->guilds as $guildName => $guild) {
			delay(0.1);
			$this->registerEmojis($guild);
		}
	}

	#[
		NCA\Event(
			name: [
				"discord(guild_create)",
				"discord(guild_update)",
			],
			description: "Handle discord guild changes",
			defaultStatus: 1
		),
	]
	public function processDiscordGuildMessages(DiscordGatewayEvent $event): void {
		$guild = new Guild();

		/** @var stdClass $event->payload->d */
		$guild->fromJSON($event->payload->d);
		$this->guilds[$guild->id] = $guild;
		$this->sendRequestGuildMembers($guild->id);
		async($this->registerEmojis(...), $guild);
		async(function () use ($guild): void {
			try {
				$invites = $this->discordAPIClient->getGuildInvites($guild->id);
				$this->cacheInvites($guild->id, $invites);
			} catch (DiscordException $e) {
				if ($e->getCode() !== 403) {
					return;
				}
				$this->logger->warning(
					"Your bot doesn't have enough rights to manage ".
					"invitations for the Discord server \"{discordServer}\"",
					[
						"discordServer" => $guild->name,
					]
				);
				$this->noManageInviteRights[$guild->id] = true;
			}
		});
		foreach ($guild->voice_states as $voiceState) {
			if (!isset($voiceState->user_id)) {
				continue;
			}
			$member = $this->discordAPIClient->getGuildMember($guild->id, $voiceState->user_id);
			$voiceState->member = $member;
		}
		foreach ($guild->channels as $channel) {
			if (!isset($channel->name)) {
				continue;
			}
			if ($channel->type === $channel::GUILD_TEXT) {
				$dc = new RoutedChannel($channel->name, $channel->id);
				Registry::injectDependencies($dc);
				$this->messageHub
					->registerMessageReceiver($dc)
					->registerMessageEmitter($dc);
			} elseif (in_array($channel->type, [$channel::GUILD_VOICE, $channel::GUILD_STAGE_VOICE], true)) {
				$dc = new RoutedChannel("< {$channel->name}", $channel->id);
				Registry::injectDependencies($dc);
				$this->messageHub
					->registerMessageEmitter($dc);
			} else {
				continue;
			}
		}
		$dm = new DiscordMsg();
		Registry::injectDependencies($dm);
		$this->messageHub
			->registerMessageReceiver($dm)
			->registerMessageEmitter($dm);
	}

	#[
		NCA\Event(
			name: "discord(guild_delete)",
			description: "Handle discord guild leave",
			defaultStatus: 1
		),
	]
	public function processDiscordGuildDeleteMessages(DiscordGatewayEvent $event): void {
		$guildId = $event->payload->d?->id ?? null;
		if (is_string($guildId)) {
			$guild = $this->guilds[$guildId] ?? null;
			if (isset($guild)) {
				$this->logger->notice("Left Discord server {serverName}", [
					"serverName" => $guild->name,
				]);
			} else {
				$this->logger->notice("Left Discord server id {guildId}", [
					"guildId" => $guildId,
				]);
			}
			unset($this->guilds[$guildId]);
			unset($this->invites[$guildId]);
		}
	}

	#[
		NCA\Event(
			name: [
				"discord(channel_create)",
				"discord(channel_update)",
				"discord(channel_delete)",
			],
			description: "Handle discord channel changes",
			defaultStatus: 1
		),
	]
	public function processDiscordChannelMessages(DiscordGatewayEvent $event): void {
		$channel = new DiscordChannel();

		/** @var stdClass $event->payload->d */
		$channel->fromJSON($event->payload->d);
		// Not a guild-channel? Must be a DM channel which we don't cache anyway
		if (!isset($channel->guild_id)) {
			return;
		}
		if (!isset($this->guilds[$channel->guild_id])) {
			$this->logger->error("Received channel info for unknown guild");
			return;
		}

		/** @psalm-suppress UnsupportedPropertyReferenceUsage */
		$channels = &$this->guilds[$channel->guild_id]->channels;
		if ($event->payload->t === "CHANNEL_CREATE") {
			$channels []= $channel;
			if ($channel->type !== $channel::GUILD_TEXT || !isset($channel->name)) {
				return;
			}
			$dc = new RoutedChannel($channel->name, $channel->id);
			Registry::injectDependencies($dc);
			$this->messageHub
				->registerMessageReceiver($dc)
				->registerMessageEmitter($dc);
			return;
		}
		if ($event->payload->t === "CHANNEL_DELETE") {
			$channels = array_values(
				array_filter(
					$channels,
					function (DiscordChannel $c) use ($channel) {
						return $c->id !== $channel->id;
					}
				)
			);
			$fullName = Source::DISCORD_PRIV . "({$channel->name})";
			$this->messageHub
				->unregisterMessageEmitter($fullName)
				->unregisterMessageReceiver($fullName);
			return;
		}
		if ($event->payload->t === "CHANNEL_UPDATE") {
			for ($i = 0; $i < count($channels); $i++) {
				if ($channels[$i]->id === $channel->id) {
					$oldChannel = $channels[$i];
					$channels[$i] = $channel;
					break;
				}
			}
			if (!isset($oldChannel)) {
				return;
			}
			$fullName = Source::DISCORD_PRIV . "({$oldChannel->name})";
			$this->messageHub
				->unregisterMessageEmitter($fullName)
				->unregisterMessageReceiver($fullName);

			$dc = new RoutedChannel($channel->name??$channel->id, $channel->id);
			Registry::injectDependencies($dc);
			$this->messageHub
				->registerMessageReceiver($dc)
				->registerMessageEmitter($dc);
			return;
		}
	}

	#[NCA\Event(
		name: "discord(ready)",
		description: "Handle discord READY event",
		defaultStatus: 1
	)]
	public function processDiscordReady(DiscordGatewayEvent $event): void {
		$payload = $event->payload;

		/** @var stdClass $payload->d */
		$this->sessionId = $payload->d->session_id;
		$user = new DiscordUser();
		$user->fromJSON($payload->d->user);
		$this->me = $user;
		$this->logger->notice(
			"Successfully logged into Discord Gateway as ".
			$user->username . "#" . $user->discriminator
		);
		$this->mustReconnect = true;
		$this->reconnectDelay = 5;
		$this->reconnectUrl = $payload->d->resume_gateway_url;
		async($this->discordSlashCommandController->syncSlashCommands(...));
	}

	#[NCA\Event(
		name: "discord(resumed)",
		description: "Handle discord RESUMED event",
		defaultStatus: 1
	)]
	public function processDiscordResumed(DiscordGatewayEvent $event): void {
		if (!isset($this->me)) {
			return;
		}
		$this->logger->notice(
			"Session successfully resumed as ".
			$this->me->username . "#" . $this->me->discriminator
		);
		$this->mustReconnect = true;
	}

	#[NCA\Event(
		name: "discord(voice_state_update)",
		description: "Keep track of people in the voice chat",
		defaultStatus: 1
	)]
	public function trackVoiceStateChanges(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		$voiceState = new VoiceState();

		/** @var stdClass $payload->d */
		$voiceState->fromJSON($payload->d);
		if (!isset($voiceState->channel_id) || $voiceState->channel_id === "") {
			$this->handleVoiceChannelLeave($voiceState);
		} else {
			$this->handleVoiceChannelJoin($voiceState);
		}
	}

	/**
	 * @return array<string,array<string,array<?string>>>
	 *
	 * @psalm-return array<string,array<string,list<?string>>>
	 */
	public function getPlayersInVoiceChannels(): array {
		$channels = [];
		foreach ($this->guilds as $guildId => $guild) {
			foreach ($guild->voice_states as $voiceState) {
				if (!isset($voiceState->channel_id)) {
					continue;
				}
				$channel = $this->getChannel($voiceState->channel_id);
				if (!isset($channel) || !isset($channel->name)) {
					continue;
				}
				$channels[$guild->name] ??= [];
				if (!isset($voiceState->member)) {
					continue;
				}
				$channels[$guild->name][$channel->name] ??= [];
				$player = $this->discordGatewayCommandHandler->getNameForDiscordId($voiceState->member->user->id??"") ?? $voiceState->member->getName();
				$channels[$guild->name][$channel->name] []= $player;
			}
		}
		return $channels;
	}

	public function handleAsyncVoiceChannelJoin(DiscordChannel $channel, VoiceState $voiceState): void {
		if (!isset($voiceState->guild_id) || !isset($voiceState->user_id)) {
			return;
		}
		$member = $this->discordAPIClient->getGuildMember(
			$voiceState->guild_id,
			$voiceState->user_id,
		);
		$event = new DiscordVoiceJoinEvent(
			discord_channel: $channel,
			member: $member
		);
		$this->eventManager->fireEvent($event);
	}

	#[
		NCA\Event(
			name: [
				DiscordVoiceJoinEvent::EVENT_MASK,
				DiscordVoiceLeaveEvent::EVENT_MASK,
			],
			description: "Announce if people join or leave voice chat"
		)
	]
	public function announceVoiceStateChange(DiscordVoiceEvent $event): void {
		$e = new Online();
		$userId = null;
		if (isset($event->member->user)) {
			$userId = $this->discordGatewayCommandHandler->getNameForDiscordId($event->member->user->id);
		}
		$e->char = new Character($userId ?? $event->member->getName());
		$chanName = $event->discord_channel->name ?? $event->discord_channel->id;
		if ($event instanceof DiscordVoiceLeaveEvent) {
			$msg = $e->char->name.
				" has left the voice channel <highlight>{$chanName}<end>.";
			$e->online = false;
		} else {
			$msg = $e->char->name.
				" has entered the voice channel <highlight>{$chanName}<end>.";
			$e->online = true;
		}
		$e->message = $msg;
		$rEvent = new RoutableEvent(
			type: RoutableEvent::TYPE_EVENT,
			path: [new Source(
				Source::DISCORD_PRIV,
				isset($event->discord_channel->name)
					? "< {$event->discord_channel->name}"
					: $event->discord_channel->id
			)],
			data: $e
		);

		$this->messageHub->handle($rEvent);
	}

	#[NCA\Event(
		name: "discord(guild_member_add)",
		description: "Connect invited members to their AO account",
		defaultStatus: 1
	)]
	public function connectNewUsersWithAO(DiscordGatewayEvent $event): void {
		$userId = $event->payload->d?->user?->id ?? null;
		$guildId = $event->payload->d?->guild_id ?? null;
		if (!isset($userId) || !isset($guildId) || isset($this->noManageInviteRights[$guildId])) {
			return;
		}
		try {
			$invites = $this->discordAPIClient->getGuildInvites($guildId);
		} catch (Throwable) {
			return;
		}
		$this->connectJoinedUserToAO($guildId, $invites, $userId);
	}

	public function formatDiscordNick(string $aoCharacter): ?string {
		if ($this->discordRenameUsers === self::RENAME_OFF) {
			return null;
		}
		$replace = [
			"{name}" => $aoCharacter,
			"{org}" => $this->relayController->getGuildAbbreviation(),
		];
		return str_replace(array_keys($replace), array_values($replace), $this->discordRenameUsers);
	}

	/** Rename/assign ranks to linked Discord <-> Ao Accounts */
	public function handleAccountLinking(string $guildId, string $userId, string $aoName): void {
		$discordNick = $this->formatDiscordNick($aoName);
		$discordRole = $this->discordAssignRole;
		if (isset($discordNick) || $discordRole !== "") {
			$data = [];
			if (isset($discordNick)) {
				$data["nick"] = $discordNick;
				$this->logger->info("Renaming Discord ID {userId} to {aoChar}", [
					"userId" => $userId,
					"aoChar" => $aoName,
				]);
			}
			if (strlen($discordRole)) {
				$data["roles"] = explode(":", $discordRole);
				$this->logger->info("Assigning Discord ID {userId} role(s) {role}", [
					"userId" => $userId,
					"role" => $discordRole,
				]);
			}
			$json = json_encode($data);
			async($this->discordAPIClient->modifyGuildMember(...), $guildId, $userId, $json);
		}
	}

	#[
		NCA\Event(
			name: "timer(1h)",
			description: "Delete expired Discord invites",
			defaultStatus: 1,
		)
	]
	public function deleteExpiredInvites(): void {
		$this->db->table(self::DB_TABLE)
			->where("expires", "<", time())
			->delete();
	}

	/** See statistics about the current Discord connection */
	#[NCA\HandlesCommand("discord")]
	public function seeDiscordStats(CmdContext $context): void {
		if ($this->discordController->discordBotToken === 'off') {
			$context->reply("This bot isn't configured to connect to Discord yet.");
			return;
		}
		if (!$this->isConnected() || !isset($this->me)) {
			$context->reply("The bot is currently not connected to Discord.");
			return;
		}
		$guildBlobs = [];
		foreach ($this->guilds as $guildId => $guild) {
			$guildBlobs []= $this->renderGuild($context, $guild);
		}
		$blob = join("\n\n", $guildBlobs);
		$context->reply(
			$this->text->blobWrap(
				"Connected as {$this->me->username}#{$this->me->discriminator} to ",
				$this->text->makeBlob(
					count($this->guilds) . " Discord server".
					((count($this->guilds) !== 1) ? "s" : ""),
					$blob
				)
			)
		);
	}

	/** Let the bot connect to Discord. Only needed in case of errors. */
	#[NCA\HandlesCommand("discord connect/disconnect")]
	public function connectCommand(
		CmdContext $context,
		#[NCA\Str("connect")]
		string $action,
	): void {
		$botToken = $this->discordController->discordBotToken;
		if (empty($botToken) || $botToken === 'off') {
			$context->reply("You need to configure a Discord token in order to connect.");
			return;
		}
		if ($this->isConnected()) {
			$context->reply("The bot is already connected to Discord.");
			return;
		}
		async($this->connectToGateway(...));
		$context->reply("Connecting to Discord.");
	}

	/** Let the bot disconnect from Discord. */
	#[NCA\HandlesCommand("discord connect/disconnect")]
	public function disconnectCommand(
		CmdContext $context,
		#[NCA\Str("disconnect")]
		string $action,
	): void {
		if (!$this->isConnected() || !isset($this->client)) {
			$context->reply("The bot is already disconnected from Discord.");
			return;
		}
		$this->mustReconnect = false;
		$this->logger->notice("Closing Discord gateway connection.");
		$this->client->close();
		$context->reply("Successfully disconnected from Discord.");
	}

	/** Request an invite to the org's Discord server that links to this character */
	#[NCA\HandlesCommand("discord create invite for yourself")]
	public function requestDiscordInvite(
		CmdContext $context,
		#[NCA\Str("join")]
		string $action,
		?string $discordServer,
	): void {
		$aoChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($aoChar);
		$isLinked = $this->db->table(DiscordGatewayCommandHandler::DB_TABLE)
			->whereIn("name", [$aoChar, ...$alts])
			->whereNull("token")
			->whereNotNull("confirmed")
			->exists();
		if ($isLinked) {
			$context->reply(
				"Your account is already linked to a Discord user and ".
				"you cannot create invitations for someone else."
			);
			return;
		}
		if (!$context->isDM()) {
			$context->reply(
				"For security reasons, a personal Discord server token ".
				"can only be requested in a tell or other direct message."
			);
			return;
		}
		if ($this->discordController->discordBotToken === 'off') {
			$context->reply("This bot isn't configured to connect to Discord yet.");
			return;
		}

		$guildIds = array_keys($this->guilds);
		if (count($guildIds) === 0) {
			$context->reply("Please wait until the Discord connection is established.");
			return;
		}
		if (isset($discordServer)) {
			if (!preg_match("/^\d+$/", $discordServer)) {
				foreach ($this->guilds as $guildId => $guild) {
					if (strcasecmp($guild->name, $discordServer) === 0) {
						$discordServer = $guild->id;
					}
				}
			}
			$guild = $this->guilds[$discordServer] ?? null;
			if (!isset($guild)) {
				$context->reply(
					"The bot is not a member of the Discord server ".
					"<highlight>{$discordServer}<end>."
				);
				return;
			}
			if (!isset($this->invites[$discordServer])) {
				$context->reply(
					"Your Discord bot does not have the required rights ".
					"(MANAGE_GUILD and CREATE_INSTANT_INVITE) to manage ".
					"invites for {$guild->name}."
				);
				return;
			}
			$guildIds = [$discordServer];
		} else {
			$guildIds = array_keys($this->invites);
			if (count($guildIds) > 1) {
				$blobs = ["<header2>Available Discord servers<end>"];
				foreach ($this->invites as $guildId => $guildInvites) {
					$guild = $this->guilds[$guildId] ?? null;
					if (!isset($guild)) {
						continue;
					}
					$joinLink = $this->text->makeChatcmd(
						"request invite",
						"/tell <myname> discord invite {$guild->id}"
					);
					$blobs []= "<tab>[{$joinLink}] <highlight>{$guild->name}<end> (ID {$guild->id})";
				}
				$context->reply(
					$this->text->makeBlob(
						"Choose which Discord server to join",
						join("\n", $blobs)
					)
				);
				return;
			}
			if (count($guildIds) === 0) {
				$context->reply(
					"Your Discord bot does not have the required rights ".
					"(MANAGE_GUILD and CREATE_INSTANT_INVITE) to manage ".
					"invites."
				);
				return;
			}
		}

		$aoChar = $this->altsController->getMainOf($context->char->name);

		/** @var ?DBDiscordInvite */
		$oldInvite = $this->db->table(self::DB_TABLE)
			->where("character", $aoChar)
			->where("expires", ">", time())
			->asObj(DBDiscordInvite::class)
			->first();
		if (isset($oldInvite)) {
			$invite = new DiscordChannelInvite();
			$invite->code = $oldInvite->token;
			$invite->guild = $this->guilds[$guildIds[0]];
			$msg = $this->getInviteReply($invite);
			$context->reply($msg);
			return;
		}
		$guild = $this->guilds[$guildIds[0]] ?? null;
		if (!isset($guild) || !isset($guild->system_channel_id)) {
			return;
		}
		$invitation = $this->discordAPIClient->createChannelInvite(
			$guild->system_channel_id,
			3600,
			1
		);
		$main = $this->altsController->getMainOf($context->char->name);
		$this->registerDiscordChannelInvite($invitation, $main);
		$msg = $this->getInviteReply($invitation);
		$context->reply($msg);
	}

	/** List all currently available invites */
	#[NCA\HandlesCommand("discord see invites")]
	public function listDiscordInvites(
		CmdContext $context,
		#[NCA\Str("invites", "invitations")]
		string $action
	): void {
		if ($this->discordController->discordBotToken === 'off') {
			$context->reply("This bot isn't configured to connect to Discord yet.");
			return;
		}
		if (!$this->isConnected()) {
			$context->reply("The bot is currently not connected to Discord.");
			return;
		}
		$queue = [];
		foreach ($this->guilds as $guildId => $guild) {
			$queue[$guildId] = async($this->discordAPIClient->getGuildInvites(...), $guild->id);
		}
		$invitations = await($queue);
		foreach ($invitations as $guildId => $invites) {
			$this->cacheInvites((string)$guildId, $invites);
		}
		$context->reply($this->renderInvites());
	}

	/** Let the bot leave a Discord server */
	#[NCA\HandlesCommand("discord leave server")]
	public function leaveDiscordServer(
		CmdContext $context,
		#[NCA\Str("leave")]
		string $action,
		string $guildId,
	): void {
		if ($this->discordController->discordBotToken === 'off') {
			$context->reply("This bot isn't configured to connect to Discord yet.");
			return;
		}
		if (!$this->isConnected()) {
			$context->reply("The bot is currently not connected to Discord.");
			return;
		}
		$guild = $this->guilds[$guildId] ?? null;
		if (!isset($guild)) {
			$context->reply(
				"This bot is not a member of Discord server ".
				"<highlight>{$guildId}<end>."
			);
			return;
		}
		$informDelete = function (DiscordGatewayEvent $event) use ($context, $guild, &$informDelete): void {
			$guildId = $event->payload->d?->id ?? null;
			if ($guildId !== $guild->id) {
				return;
			}
			$context->reply(
				"Successfully left the Discord server ".
				"<highlight>{$guild->name}<end>."
			);
			$this->eventManager->unsubscribe(
				"discord(guild_delete)",
				$informDelete
			);
		};
		$this->eventManager->subscribe(
			"discord(guild_delete)",
			$informDelete
		);
		try {
			$this->discordAPIClient->leaveGuild($guildId);
		} catch (Throwable) {
			$context->reply(
				"There was an error leaving the Discord server ".
				"<highlight>{$guild->name}<end>. ".
				"See the logs for details."
			);
			$this->eventManager->unsubscribe(
				"discord(guild_delete)",
				$informDelete
			);
		}
	}

	/** Show scheduled events on the Discord server */
	#[NCA\HandlesCommand("discord show events")]
	public function listDiscordEvents(
		CmdContext $context,
		#[NCA\Str("events")]
		string $action,
		?string $guildId,
	): void {
		if ($this->discordController->discordBotToken === 'off') {
			$context->reply("This bot isn't configured to connect to Discord yet.");
			return;
		}
		if (!$this->isConnected()) {
			$context->reply("The bot is currently not connected to Discord.");
			return;
		}
		if (empty($this->guilds)) {
			$context->reply("This bot is not a member of a Discord server.");
			return;
		}
		$guilds = array_filter(
			$this->guilds,
			fn (Guild $guild): bool => in_array("COMMUNITY", $guild->features)
		);
		if (empty($guilds)) {
			$context->reply("This bot is not a member of a Discord server with community features.");
			return;
		}
		$guildIds = [];
		if (isset($guildId)) {
			$guild = $guilds[$guildId] ?? null;
			if (!isset($guild)) {
				$context->reply("This bot is not a member of Discord server <highlight>{$guildId}<end>.");
				return;
			}
			$guildIds = [$guildId];
		} else {
			foreach ($guilds as $guildId => $guild) {
				$guildIds []= $guild->id;
			}
		}
		$blobs = [];

		/** @var DiscordScheduledEvent[] */
		$allEvents = [];
		foreach ($guildIds as $guildId) {
			$guildName = $guilds[$guildId]->name;
			try {
				$events = $this->discordAPIClient->getGuildEvents($guildId);
				$allEvents = array_merge($allEvents, $events);
			} catch (Throwable $e) {
				$this->logger->error(
					"Error reading events from the Discord server: {error}",
					[
						"error" => $e->getMessage(),
						"exception" => $e,
					]
				);
				$context->reply(
					"There was an error reading events from the Discord server " .
						"<highlight>{$guildName}<end>. " .
						"See the logs for details."
				);
				return;
			}
		}
		usort(
			$allEvents,
			function (DiscordScheduledEvent $a, DiscordScheduledEvent $b): int {
				return $a->scheduled_start_time->getTimestamp()
					<=> $b->scheduled_start_time->getTimestamp();
			}
		);
		$renderers = [];
		foreach ($allEvents as $event) {
			$renderers [] = async($this->renderEvent(...), $guilds[$event->guild_id], $event);
		}
		try {
			$blobs = await($renderers);
		} catch (Throwable $e) {
			$this->logger->error(
				"Error reading event-details from the Discord server: {error}",
				[
					"error" => $e->getMessage(),
					"exception" => $e,
				]
			);
			$context->reply(
				"There was an error reading event-details from the Discord ".
				"server. See the logs for details."
			);
			return;
		}
		$msg = $this->text->makeBlob(
			"Upcoming events (" . count($blobs) . ")",
			join("\n\n", $blobs)
		);
		$context->reply($msg);
	}

	#[NCA\Event(
		name: "discord(guild_scheduled_event_create)",
		description: "Announce new Discord events"
	)]
	public function announceNewDiscordEvent(DiscordGatewayEvent $e): void {
		$event = new DiscordScheduledEvent();
		$event->fromJSON($e->payload->d);
		$guild = $this->guilds[$event->guild_id]??null;
		if (!isset($guild)) {
			return;
		}

		$blob = $this->renderEvent($guild, $event);
		$msgs = $this->text->blobWrap(
			"New Discord event: ",
			$this->text->makeBlob($event->name, $blob),
		);
		foreach ($msgs as $msg) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->prependPath(new Source("discord", "event-create"));
			$this->messageHub->handle($rMsg);
		}
	}

	#[NCA\Event(
		name: "discord(guild_scheduled_event_update)",
		description: "Announce Discord event started"
	)]
	public function announceStartedDiscordEvent(DiscordGatewayEvent $e): void {
		$event = new DiscordScheduledEvent();
		$event->fromJSON($e->payload->d);
		$guild = $this->guilds[$event->guild_id]??null;
		if (!isset($guild)) {
			return;
		}
		if ($event->status !== $event::STATUS_ACTIVE) {
			return;
		}

		$blob = $this->renderEvent($guild, $event);
		$msgs = $this->text->blobWrap(
			"Discord event started: ",
			$this->text->makeBlob($event->name, $blob),
		);
		foreach ($msgs as $msg) {
			$rMsg = new RoutableMessage($msg);
			$rMsg->prependPath(new Source("discord", "event-start"));
			$this->messageHub->handle($rMsg);
		}
	}

	#[NCA\Event(
		name: "discord(guild_scheduled_event_delete)",
		description: "Announce cancelled Discord events"
	)]
	public function announceRemovedDiscordEvent(DiscordGatewayEvent $e): void {
		$event = new DiscordScheduledEvent();
		$event->fromJSON($e->payload->d);
		$guild = $this->guilds[$event->guild_id]??null;
		if (!isset($guild)) {
			return;
		}
		$rMsg = new RoutableMessage("Discord event <highlight>{$event->name}<end> was cancelled.");
		$rMsg->prependPath(new Source("discord", "event-delete"));
		$this->messageHub->handle($rMsg);
	}

	#[NCA\Event(
		name: "discord(guild_scheduled_event_update)",
		description: "Announce Discord event ended"
	)]
	public function announceEndedDiscordEvent(DiscordGatewayEvent $e): void {
		$event = new DiscordScheduledEvent();
		$event->fromJSON($e->payload->d);
		$guild = $this->guilds[$event->guild_id]??null;
		if (!isset($guild)) {
			return;
		}
		if ($event->status !== $event::STATUS_COMPLETED) {
			return;
		}
		$rMsg = new RoutableMessage("Discord event <highlight>{$event->name}<end> is now over.");
		$rMsg->prependPath(new Source("discord", "event-end"));
		$this->messageHub->handle($rMsg);
	}

	protected function renderEvent(Guild $guild, DiscordScheduledEvent $event): string {
		$name = $event->name;
		if ($event->entity_type === $event::TYPE_EXTERNAL) {
			$name .= " (external)";
		}
		$blob = "<header2>{$name}<end>";
		if (isset($event->description)) {
			$descr = DiscordRelayController::formatMessage($event->description);
			if ($descr === '') {
				$descr = "&lt;no description&gt;";
			}
			$blob .= "\n<tab><i>".
			join("</i>\n<tab><i>", explode("\n", $descr)) . "</i>".
			"\n";
		}
		$blob .= "\n<tab>When: <highlight>".
		$this->util->date($event->scheduled_start_time->getTimestamp()).
		"<end>";
		if (isset($event->scheduled_end_time)) {
			$blob .= " - <highlight>".
			$this->util->date($event->scheduled_end_time->getTimestamp()).
			"<end>";
		}
		if (isset($event->entity_metadata->location)) {
			$blob .= "\n<tab>Where: <highlight>".
			$event->entity_metadata->location.
			"<end>";
		} elseif (isset($event->channel_id)) {
			$channel = $this->getChannel($event->channel_id);
			if (isset($channel, $channel->name)) {
				$blob .= "\n<tab>Where: <highlight>".
				$guild->name . " " . $this->renderSingleChannel($channel).
				"<end>";
			}
		}
		try {
			if (isset($event->creator_id)) {
				$creator =(
					$this->discordGatewayCommandHandler->getNameForDiscordId($event->creator_id)
					?? $event->creator?->username
					?? ($this->discordAPIClient->getUser($event->creator_id))->username
					?? "UNKNOWN"
				);
				$blob .= "\n<tab>Created by: <highlight>{$creator}<end>";
			}
		} catch (Throwable) {
			// IGNORE
		}
		if (isset($event->user_count)) {
			$blob .= "\n<tab>Attending: <highlight>{$event->user_count} ".
			$this->text->pluralize("person", $event->user_count).
			"<end>";
		}
		return $blob;
	}

	/**
	 * Check if a close code allowed reconnecting
	 *
	 * @param null|int $code The close code from the Discord server
	 *
	 * @return bool Are we allowed to reconnect?
	 */
	protected function shouldReconnect(?int $code=null): bool {
		if ($code === null) {
			return true; // No idea what went wrong, most likely network issues
		}
		return !in_array(
			$code,
			[
				CloseEvents::NORMAL,
				CloseEvents::AUTHENTICATION_FAILED,
				CloseEvents::INVALID_SHARD,
				CloseEvents::SHARDING_REQUIRED,
				CloseEvents::INVALID_API_VERSION,
				CloseEvents::INVALID_INTENT,
				CloseEvents::DISALLOWED_INTENT,
			]
		);
	}

	protected function canResumeSessionAfterClose(?int $code=null): bool {
		if ($code === null || $code < 4000) {
			return true;
		}
		return in_array(
			$code,
			[
				CloseEvents::INVALID_SEQ,
				CloseEvents::SESSION_TIMED_OUT,
			]
		);
	}

	/** @param DiscordChannelInvite[] $invites */
	protected function cacheInvites(string $guildId, array $invites): void {
		$this->invites[$guildId] = $invites;
	}

	/** Remove a Discord UserId from all voice channels */
	protected function removeFromVoice(string $userId): ?VoiceState {
		$oldState = $this->getCurrentVoiceState($userId);
		if ($oldState === null || !isset($oldState->guild_id)) {
			return null;
		}
		$this->guilds[$oldState->guild_id]->voice_states = array_values(
			array_filter(
				$this->guilds[$oldState->guild_id]->voice_states ?? [],
				function (VoiceState $state) use ($oldState): bool {
					return $state->user_id !== $oldState->user_id;
				}
			)
		);
		return $oldState;
	}

	protected function handleVoiceChannelLeave(VoiceState $voiceState): void {
		if (!isset($voiceState->user_id)) {
			return;
		}
		$oldState = $this->removeFromVoice($voiceState->user_id);
		if ($oldState === null) {
			return;
		}
		$guildId = $voiceState->guild_id ?? null;
		if (!isset($guildId) && isset($oldState->channel_id)) {
			$channel = $this->getChannel($oldState->channel_id);
			if (isset($channel->guild_id)) {
				$guildId = $channel->guild_id;
			}
		}
		if (!isset($guildId) || !isset($voiceState->user_id)) {
			return;
		}
		$member = $this->discordAPIClient->getGuildMember($guildId, $voiceState->user_id);
		if (!isset($oldState->channel_id)) {
			return;
		}
		$discordChannel = $this->getChannel($oldState->channel_id);
		if (!isset($discordChannel)) {
			return;
		}
		$event = new DiscordVoiceLeaveEvent(
			discord_channel: $discordChannel,
			member: $member,
		);
		$this->eventManager->fireEvent($event);
	}

	protected function handleVoiceChannelJoin(VoiceState $voiceState): void {
		if (isset($voiceState->user_id)) {
			$oldState = $this->getCurrentVoiceState($voiceState->user_id);
			if (isset($oldState) && $oldState->channel_id === $voiceState->channel_id) {
				return;
			}
			$this->removeFromVoice($voiceState->user_id);
		}
		if (!isset($voiceState->guild_id)) {
			return;
		}
		$this->guilds[$voiceState->guild_id]->voice_states []= $voiceState;
		if (isset($voiceState->channel_id)) {
			$channel = $this->lookupChannel($voiceState->channel_id);
			if (isset($channel)) {
				$this->handleAsyncVoiceChannelJoin($channel, $voiceState);
			}
		}
	}

	/**
	 * Try to find out if a newly joined user used one of our AO invitations
	 *
	 * @param DiscordChannelInvite[] $invites
	 */
	protected function connectJoinedUserToAO(string $guildId, array $invites, string $userId): void {
		/** @var DiscordChannelInvite[] */
		$oldInvites = $this->invites[$guildId] ?? [];
		$this->invites[$guildId] = $invites;
		$validOldInvites = [];
		foreach ($oldInvites as $invite) {
			if (isset($invite->expires_at) && $invite->expires_at->getTimestamp() < time()) {
				continue;
			}
			$validOldInvites []= $invite;
		}
		$oldInviteCodes = array_column($validOldInvites, "code");
		$inviteCodes = array_column($invites, "code");
		$usedInviteCodes = array_diff($oldInviteCodes, $inviteCodes);
		if (count($usedInviteCodes)  !== 1) {
			$this->logger->info("Unable to exactly determine which Discord invite code was used");
			return;
		}
		$inviteCode = $usedInviteCodes[array_keys($usedInviteCodes)[0]];
		try {
			/** @var DBDiscordInvite */
			$invite = $this->db->table(self::DB_TABLE)
				->where("token", $inviteCode)
				->asObj(DBDiscordInvite::class)
				->firstOrFail();
		} catch (ItemNotFoundException $e) {
			$this->logger->notice("Cannot find invitation {token} in the database, cannot link user", [
				"token" => $inviteCode,
			]);
			return;
		}
		$this->db->table(self::DB_TABLE)->delete($invite->id);

		$this->logger->notice(
			"Discord user {userId} joined the server using invite code {token}, ".
			"which belongs to {character}",
			[
				"userId" => $userId,
				"token" => $inviteCode,
				"character" => $invite->character,
			]
		);
		$this->handleAccountLinking($guildId, $userId, $invite->character);

		/** @var ?DiscordMapping */
		$data = $this->db->table(DiscordGatewayCommandHandler::DB_TABLE)
			->where("discord_id", $userId)
			->whereNotNull("confirmed")
			->asObj(DiscordMapping::class)
			->first();
		if ($data !== null) {
			$this->logger->warning("The Discord user {userId} is already connected to {aoChar}", [
				"userId" => $userId,
				"aoChar" => $data->name,
			]);
			return;
		}
		$this->db->table(DiscordGatewayCommandHandler::DB_TABLE)
			->where("discord_id", $userId)
			->where("name", $invite->character)
			->delete();
		$mapping = new DiscordMapping();
		$mapping->name = $invite->character;
		$mapping->discord_id = $userId;
		$mapping->confirmed = time();
		$mapping->created = time();
		$this->db->insert(DiscordGatewayCommandHandler::DB_TABLE, $mapping);
		$this->logger->notice("The Discord user {userId} is now linked to {aoChar}", [
			"userId" => $mapping->discord_id,
			"aoChar" => $mapping->name,
		]);
	}

	protected function getCurrentVoiceState(string $userId): ?VoiceState {
		foreach ($this->guilds as $guildId => $guild) {
			foreach ($guild->voice_states as $voice) {
				if ($voice->user_id === $userId) {
					$voice->guild_id = (string)$guildId;
					return $voice;
				}
			}
		}
		return null;
	}

	protected function renderGuild(CmdContext $context, Guild $guild): string {
		$joinLink = "";
		$leaveLink = "";
		if ($this->commandManager->couldRunCommand($context, "discord leave {$guild->id}")) {
			$leaveLink = " [" . $this->text->makeChatcmd(
				"kick bot",
				"/tell <myname> discord leave {$guild->id}"
			) . "]";
		}
		$aoChar = $this->altsController->getMainOf($context->char->name);
		$isLinked = $this->db->table(DiscordGatewayCommandHandler::DB_TABLE)
			->whereIn("name", [$aoChar, $context->char->name])
			->whereNull("token")
			->whereNotNull("confirmed")
			->exists();
		$canRunJoin = $this->commandManager->couldRunCommand($context, "discord join {$guild->id}");
		if ($canRunJoin && !$isLinked && isset($this->invites[$guild->id])) {
			$joinLink = " [" . $this->text->makeChatcmd(
				"request invite",
				"/tell <myname> discord join {$guild->id}"
			) . "]";
		}
		$lines = [];
		$lines []= "<header2>{$guild->name}<end>{$leaveLink}{$joinLink}";
		$channels = $guild->channels;
		usort(
			$channels,
			function (DiscordChannel $a, DiscordChannel $b): int {
				return ($a->position ?? 0) <=> ($b->position ?? 0);
			}
		);
		foreach ($channels as $channel) {
			if ($channel->type === $channel::GUILD_CATEGORY || isset($channel->parent_id)) {
				continue;
			}
			$lines []= "<tab><highlight>" . $this->renderSingleChannel($channel) . "<end>";
		}
		foreach ($channels as $channel) {
			if ($channel->type !== $channel::GUILD_CATEGORY || isset($channel->parent_id)) {
				continue;
			}
			$lines []= "<tab><highlight>" . $this->renderSingleChannel($channel) . "<end>";
			foreach ($guild->channels as $sChannel) {
				if (!isset($sChannel->parent_id) || $sChannel->parent_id !== $channel->id) {
					continue;
				}
				$lines []= "<tab><tab>" . $this->renderSingleChannel($sChannel);
			}
		}
		return join("\n", $lines);
	}

	protected function renderSingleChannel(DiscordChannel $channel): string {
		$prefix = "";
		switch ($channel->type) {
			case DiscordChannel::GUILD_TEXT:
				$prefix = "# ";
				break;
			case DiscordChannel::GUILD_VOICE:
			case DiscordChannel::GUILD_STAGE_VOICE:
				$prefix = "&lt; ";
				break;
			default:
				$prefix = "";
		}
		return $prefix . ($channel->name ?? "UNKNOWN");
	}

	protected function registerDiscordChannelInvite(DiscordChannelInvite $invite, string $main): void {
		$this->db->table(self::DB_TABLE)->insert([
			"token" => $invite->code,
			"character" => $main,
			"expires" => $invite->expires_at?->getTimestamp() ?? null,
		]);
		if (isset($invite->guild)) {
			$this->invites[$invite->guild->id] []= $invite;
		}
	}

	private function registerEmojis(Guild $guild): void {
		try {
			/** @var Collection<DBEmoji> */
			$registered = $this->db->table(self::EMOJI_TABLE)
				->where("guild_id", $guild->id)
				->asObj(DBEmoji::class);
			if (!$this->discordController->discordCustomEmojis) {
				foreach ($registered as $emoji) {
					/** @var DBEmoji $emoji */
					try {
						$this->discordAPIClient->deleteEmoji($guild->id, $emoji->emoji_id);
					} catch (DiscordException $e) {
						continue;
					}
					$this->logger->notice('Deleted server emoji :{emoji}: on {guild}', [
						'emoji' => $emoji->name,
						'guild' => $guild->name,
					]);
					$this->db->table(self::EMOJI_TABLE)->delete($emoji->id);
					$guild->emojis = (new Collection($guild->emojis))
						->where("id", "!=", $emoji->emoji_id)
						->toArray();
				}
				return;
			}
			$files = $this->fs->listFiles("res/icons/");
			foreach ($files as $file) {
				$fileName = "res/icons/{$file}";
				$this->logger->info("Found icon {fileName}", [
					"fileName" => $fileName,
				]);
				if (!$this->fs->isFile($fileName)) {
					$this->logger->info("{fileName} is not a file, skipping", [
						"fileName" => $fileName,
					]);
					continue;
				}
				$info = pathinfo($fileName);
				if (!isset($info['extension'])) {
					$this->logger->info("{fileName} doesn't have a pathinfo, skipping", [
						"fileName" => $fileName,
					]);
					continue;
				}

				$stats = $this->fs->getStatus($fileName);
				$content = $this->fs->read($fileName);
				$data = "data:image/{$info['extension']};base64,".
					base64_encode($content);

				/** @var null|Model\Emoji */
				$oldEmoji = (new Collection($guild->emojis))
					->where("name", $info['filename'])
					->first();

				/** @var null|DBEmoji */
				$oldDBEmoji = $registered->where("name", $info['filename'])->first();
				if (isset($oldEmoji, $oldEmoji->id)   && (!isset($oldDBEmoji) || !isset($stats) || $oldDBEmoji->version < $stats[9])) {
					$this->discordAPIClient->deleteEmoji($guild->id, $oldEmoji->id);
					$this->logger->notice('Deleted server emoji :{emoji}: on {guild}', [
						'emoji' => $oldEmoji->name,
						'guild' => $guild->name,
					]);
					$guild->emojis = (new Collection($guild->emojis))
						->where("id", "!=", $oldEmoji->id)
						->toArray();
					unset($oldEmoji);
				}
				if (!isset($oldEmoji)) {
					$registeredEmoji = $this->discordAPIClient->createEmoji($guild->id, $info['filename'], $data);
					$this->logger->notice('Registered server emoji :{emoji}: on {guild}', [
						'emoji' => $info['filename'],
						'guild' => $guild->name,
					]);
					$guild->emojis []= $registeredEmoji;
				} else {
					if (isset($oldDBEmoji)) {
						$this->logger->info('Skipping server emoji :{emoji}: on {guild}', [
							'emoji' => $oldDBEmoji->name,
							'guild' => $guild->name,
						]);
					}
					continue;
				}
				if (!isset($oldDBEmoji)) {
					$dbEmoji = new DBEmoji();
					$dbEmoji->emoji_id = $registeredEmoji->id;
					$dbEmoji->name = $info['filename'];
					$dbEmoji->registered = time();
					$dbEmoji->version = $stats[9]??time();
					$dbEmoji->guild_id = $guild->id;
					$this->db->insert(self::EMOJI_TABLE, $dbEmoji);
				} else {
					$oldDBEmoji->registered = time();
					$oldDBEmoji->emoji_id = $registeredEmoji->id;
					$oldDBEmoji->version = $stats[9]??time();
					$this->db->update(self::EMOJI_TABLE, "id", $oldDBEmoji);
				}
			}
		} catch (DiscordException $e) {
			if ($e->getCode() === 403) {
				$this->logger->warning(
					"Your bot doesn't have enough rights to manage ".
					'emojis for the Discord server "{discordServer}"',
					[
						"discordServer" => $guild->name,
					]
				);
			} else {
				$this->logger->error("Discord error syncing bot-emojis with Discord server: {error}", [
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error("Error syncing bot-emojis with Discord server: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	private function countOutgoingPackets(string $handleId): void {
		if (!isset($this->client) || $this->client->isClosed()) {
			EventLoop::cancel($handleId);
			return;
		}
		$msgsSent = $this->client->getCount(WebsocketCount::MessagesSent);
		if ($this->lastPackets > $msgsSent) {
			$this->outStats->inc($msgsSent);
		} else {
			$this->outStats->inc($msgsSent - $this->lastPackets);
		}
	}

	private function connectToGateway(): void {
		$this->reconnectUrl = null;
		do {
			if (!isset($this->reconnectUrl)) {
				$gwTry = 0;
				do {
					$gwTry++;
					try {
						$gateway = $this->discordAPIClient->getGateway();
					} catch (Throwable $e) {
						$retryDelay = $gwTry**2;
						$this->logger->notice("Error reading Discord gateway: {error}, retrying in {retry}s", [
							"error" => $e->getMessage(),
							"retry" => $retryDelay,
						]);
						delay($retryDelay);
						continue;
					}
				} while (!isset($gateway));
				$this->logger->info("{remaining} Discord connections out of {total} remaining", [
					"remaining" => $gateway->session_start_limit->remaining,
					"total" => $gateway->session_start_limit->total,
				]);
				if ($gateway->session_start_limit->remaining < 2) {
					$resetDelay = (int)ceil($gateway->session_start_limit->reset_after / 1000);
					$this->logger->warning(
						"The bot used up all its allowed connections to the Discord API. ".
						"Will try in {delay}",
						[
							"delay" => $this->util->unixtimeToReadable($resetDelay),
						]
					);
					delay($resetDelay);
					if ($this->isConnected()
						&& isset($this->client)
						&& !$this->client->isClosed()) {
						return;
					}
				}
				$handshake = new WebsocketHandshake($gateway->url . '/?v=10&encoding=json');
			} else {
				$handshake = new WebsocketHandshake($this->reconnectUrl . '?v=10&encoding=json');
			}
			$connectContext = (new ConnectContext())->withTcpNoDelay();
			$httpClient = (new HttpClientBuilder())
				->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
				->intercept(new RemoveRequestHeader('origin'))
				->build();
			$client = new Rfc6455Connector(httpClient: $httpClient);
			try {
				$connection = $client->connect($handshake, null);
				$this->client = $connection;
				$handleId = EventLoop::repeat(10, fn (string $handleId) => $this->countOutgoingPackets($handleId));
				while (null !== ($message = $connection->receive())) {
					$payload = $message->buffer();
					$this->inStats->inc();

					async($this->processWebsocketMessage(...), $payload);
				}
				if ($this->client->getCloseInfo()->isByPeer()) {
					throw new WebsocketClosedException(
						"Discord unexpectedly closed the connection",
						$this->client->getCloseInfo()->getCode(),
						$this->client->getCloseInfo()->getReason(),
					);
				}
			} catch (WebsocketConnectException $e) {
				$this->logger->error("Discord endpoint errored: {error}", [
					"error" => $e->getMessage(),
				]);
				$this->sessionId = null;
				return;
			} catch (HttpException $e) {
				$this->logger->error("Request to connect to Discord failed: {error}", [
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
				$this->sessionId = null;
				return;
			} catch (WebsocketClosedException $e) {
				if ($this->canReconnect($e->getCode())) {
					if (!$this->canResumeSessionAfterClose($e->getCode())) {
						$this->lastSequenceNumber = null;
						$this->sessionId = null;
					}
					unset($this->client);
					$this->logger->notice("Reconnecting to Discord gateway in {delay}s.", [
						"delay" => $this->reconnectDelay,
					]);
					delay($this->reconnectDelay);
					$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
					continue;
				}
				return;
			} catch (Throwable $e) {
				$this->logger->error("Error from Discord endpoint: {error}", [
					"error" => $e->getMessage(),
				]);
				$this->sessionId = null;
				return;
			} finally {
				if (isset($this->client)) {
					$msgsSent = $this->client->getCount(WebsocketCount::MessagesSent);
					if ($this->lastPackets > $msgsSent) {
						$this->outStats->inc($msgsSent);
					} else {
						$this->outStats->inc($msgsSent - $this->lastPackets);
					}
					$this->lastPackets = $msgsSent;
				}
				if (isset($handleId)) {
					EventLoop::cancel($handleId);
				}
				unset($this->client);
			}
			$this->logger->notice("Connection to Discord gracefully closed");
			if (!$this->mustReconnect) {
				$this->lastSequenceNumber = null;
				$this->sessionId = null;
			}
			delay(0.5);
		} while ($this->mustReconnect);
	}

	/** Send periodic heartbeats to the Discord gateway */
	private function sendWebsocketHeartbeat(string $watcherId): void {
		if (!$this->isConnected()
			|| !isset($this->client)
			|| $this->client->isClosed()
		) {
			EventLoop::cancel($watcherId);
			return;
		}
		$this->lastHeartbeat = time();
		$this->logger->info("Sending heartbeat");
		$this->client->sendText(json_encode(["op" => 1, "d" => $this->lastSequenceNumber]));
	}

	private function sendIdentify(): void {
		$this->guilds = [];
		$this->invites = [];
		$this->logger->notice("Logging into Discord gateway");
		$identify = new IdentifyPacket();
		$identify->token = $this->discordController->discordBotToken;
		$identify->large_threshold = 250;
		$identify->intents = Intent::GUILD_MESSAGES
			| Intent::DIRECT_MESSAGES
			| Intent::GUILD_MEMBERS
			| Intent::GUILDS
			| Intent::GUILD_VOICE_STATES
			| Intent::MESSAGE_CONTENT
			| Intent::GUILD_SCHEDULED_EVENTS;
		$login = new Payload();
		$login->op = Opcode::IDENTIFY;
		$login->d = $identify;
		if (isset($this->client)) {
			$this->client->sendText(json_encode($login));
		}
	}

	private function sendResume(): void {
		$this->logger->notice("Trying to resume old Discord gateway session");
		$resume = new ResumePacket();
		$resume->token = $this->discordController->discordBotToken;
		if (!isset($this->sessionId) || !isset($this->lastSequenceNumber)) {
			$this->logger->error("Cannot resume session, because no previous session found.");
			return;
		}
		$resume->session_id = $this->sessionId;
		$resume->seq = $this->lastSequenceNumber;
		$payload = new Payload();
		$payload->op = Opcode::RESUME;
		$payload->d = $resume;
		if (isset($this->client)) {
			$this->client->sendText(json_encode($payload));
		}
	}

	private function sendRequestGuildMembers(string $guildId): void {
		$request = new RequestGuildMembers($guildId);
		$payload = new Payload();
		$payload->op = Opcode::REQUEST_GUILD_MEMBERS;
		$payload->d = $request;
		if (isset($this->client)) {
			$this->client->sendText(json_encode($payload));
		}
	}

	private function canReconnect(int $code): bool {
		if (
			(($code === 1000 && $this->mustReconnect)
			|| $this->shouldReconnect($code))
		) {
			return true;
		} elseif ($code === CloseEvents::DISALLOWED_INTENT) {
			$this->logger->error(
				"Your bot doesn't have all the intents it needs. Please go to {url}, then ".
				"choose this bot's application, then choose \"Bot\" on the left and ".
				"activate \"Server members intent\" and \"Message content intent\" under ".
				"\"Privileged Gateway Intents\".",
				["url" => "https://discord.com/developers"]
			);
			return false;
		}
		$ref = new ReflectionClass(CloseEvents::class);
		$lookup = array_flip($ref->getConstants(ReflectionClassConstant::IS_PUBLIC));
		$this->logger->notice(
			"Discord server closed connection with code {code} ({text})",
			[
					"code" => $code,
					"text" => $lookup[$code] ?? "unknown",
				]
		);
		$this->guilds = [];
		$this->invites = [];
		$this->sessionId = null;
		return true;
	}

	/** @return string[] */
	private function renderInvites(): array {
		$blobs = [];
		$numInvites = 0;
		$charInvites = $this->db->table(self::DB_TABLE)
			->asObj(DBDiscordInvite::class)
			->keyBy("token");
		foreach ($this->guilds as $guildId => $guild) {
			$guildInvites = $this->invites[$guildId] ?? null;
			$blob = "<header2>{$guild->name}<end>";
			if (!isset($guildInvites)) {
				$blob .= "\n<tab>&lt;no access&gt;";
				$blobs []= $blob;
				continue;
			}
			if (empty($guildInvites)) {
				$blob .= "\n<tab>&lt;none&gt;";
				$blobs []= $blob;
				continue;
			}
			foreach ($guildInvites as $invite) {
				$numInvites++;
				$blob .= "\n<tab>";

				/** @var ?DBDiscordInvite */
				$charInvite = $charInvites->get($invite->code);
				if (isset($charInvite)) {
					$blob .= "for <highlight>{$charInvite->character}<end>";
				} else {
					$blob .= "code <highlight>{$invite->code}<end> [".
						$this->text->makeChatcmd(
							"join",
							"/start https://discord.gg/{$invite->code}",
						) . "]";
				}
				if (isset($invite->expires_at)) {
					$blob .= " - expires ".
						$this->util->date($invite->expires_at->getTimestamp());
				}
				if (isset($invite->inviter) && !$this->isMe($invite->inviter->id)) {
					$blob .= " - created by ".
						$invite->inviter->username.
						"#" . $invite->inviter->discriminator;
				}
			}
			$blobs []= $blob;
		}
		$msg = (array)$this->text->makeBlob(
			"Discord invites ({$numInvites})",
			join("\n\n", $blobs),
		);
		return $msg;
	}

	/** @return string[] */
	private function getInviteReply(DiscordChannelInvite $invite): array {
		$guildName = $invite->guild->name ?? "Discord server";
		$joinLink = $this->text->makeChatcmd("this link", "/start https://discord.gg/{$invite->code}");
		$blob = "<header2>Join Discord<end>\n\n".
			"Use {$joinLink} to join " . htmlentities($guildName) . ", or use the ".
			"invite code <highlight>{$invite->code}<end>\n\n".
			"<header2>Be careful<end>\n\n".
			"Linking your Discord user with an AO character effectively\n".
			"gives the Discord user the same rights. Do not give away your\n".
			"personal invite code!";
		return (array)$this->text->makeBlob("Join {$guildName}", $blob);
	}
}
