<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use JsonException;
use Nadybot\Core\{
	CommandManager,
	Event,
	EventManager,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	Registry,
	SettingManager,
	Text,
	Timer,
	Websocket,
	WebsocketClient,
	WebsocketError,
	WebsocketCallback,
};
use Nadybot\Core\Modules\DISCORD\{
	DiscordAPIClient,
	DiscordChannel,
	DiscordEmbed,
	DiscordMessageIn,
	DiscordUser,
};
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Channels\DiscordChannel as RoutedChannel;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\{
	Activity,
	CloseEvents,
	Guild,
	GuildMember,
	IdentifyPacket,
	Intent,
	Opcode,
	Payload,
	ResumePacket,
	UpdateStatus,
	VoiceState,
};

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 * @ProvidesEvent("discordmsg")
 * @ProvidesEvent("discordpriv")
 * @ProvidesEvent("discord(0)")
 * @ProvidesEvent("discord(7)")
 * @ProvidesEvent("discord(9)")
 * @ProvidesEvent("discord(10)")
 * @ProvidesEvent("discord(11)")
 * @ProvidesEvent("discord(ready)")
 * @ProvidesEvent("discord(resumed)")
 * @ProvidesEvent("discord(guild_create)")
 * @ProvidesEvent("discord(guild_update)")
 * @ProvidesEvent("discord(guild_update_delete)")
 * @ProvidesEvent("discord(guild_role_create)")
 * @ProvidesEvent("discord(guild_role_update)")
 * @ProvidesEvent("discord(guild_role_update_delete)")
 * @ProvidesEvent("discord(message_create)")
 * @ProvidesEvent("discord(message_update)")
 * @ProvidesEvent("discord(message_delete)")
 * @ProvidesEvent("discord(message_delete_bulk)")
 * @ProvidesEvent("discord(channel_create)")
 * @ProvidesEvent("discord(channel_update)")
 * @ProvidesEvent("discord(channel_delete)")
 * @ProvidesEvent("discord(channel_pins_update)")
 * @ProvidesEvent("discord(voice_state_update)")
 * @ProvidesEvent("discord_voice_join")
 * @ProvidesEvent("discord_voice_leave")
 */
class DiscordGatewayController {
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Websocket $websocket;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordGatewayCommandHandler $discordGatewayCommandHandler;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	protected ?int $lastSequenceNumber = null;
	protected ?WebsocketClient $client = null;
	protected bool $mustReconnect = false;
	protected int $lastHeartbeat = 0;
	protected int $heartbeatInterval = 40;
	protected int $reconnectDelay = 5;
	protected ?DiscordUser $me = null;
	protected ?string $sessionId = null;
	/** @var array<string,Guild> */
	protected array $guilds = [];

	/**
	 * Get a list of all guilds this bot is a member of
	 * @return array<string,Guild>
	 */
	public function getGuilds(): array {
		return $this->guilds;
	}

	/**
	 * Check if the bot is connected and authenticated to the Discord gateway
	 */
	public function isConnected(): bool {
		return !empty($this->sessionId);
	}

	/**
	 * Search for a Discord channel we are subscribed to by channel ID
	 */
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

	public function lookupChannel(string $channelId, callable $callback, ...$args): void {
		$channel = $this->getChannel($channelId);
		if (isset($channel)) {
			$callback($channel, ...$args);
			return;
		}
		$this->discordAPIClient->getChannel(
			$channelId,
			function(DiscordChannel $channel, callable $callback, ...$args): void {
				$guildId = $channel->guild_id;
				if (!isset($guildId) || !isset($this->guilds[$guildId])) {
					return;
				}
				$this->guilds[$guildId]->channels []= $channel;
				$callback($channel, ...$args);
			},
			$callback,
			...$args
		);
	}

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"discord_activity_name",
			"Game the bot is shown to play on Discord",
			"edit",
			"text",
			"Anarchy Online",
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_notify_voice_changes",
			"Show people joining or leaving voice channels",
			"edit",
			"options",
			"0",
			"off;priv;org;priv+org",
			"0;1;2;3"
		);
		$this->settingManager->registerChangeListener('discord_bot_token', [$this, "tokenChanged"]);
		$this->settingManager->registerChangeListener('discord_activity_name', [$this, "updatePresence"]);
	}

	public function updatePresence(string $settingName, string $oldValue, string $newValue): void {
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
		$this->client->send(json_encode($packet));
	}

	/**
	 * Start, stop or restart the websocket connection if the token changes
	 */
	public function tokenChanged(string $settingName, string $oldValue, string $newValue): void {
		if ($oldValue !== "" && $oldValue !== 'off' && isset($this->client)) {
			$this->logger->log("INFO", "Closing Discord gateway connection.");
			$this->client->close();
		}
		if ($newValue !== "" && $newValue !== 'off') {
			$this->timer->callLater(0, [$this, "connect"]);
		}
	}

	/**
	 * @Event("connect")
	 * @Description("Connects to the Discord server")
	 */
	public function connectToDiscordgateway(): void {
		$this->connect();
	}

	public function connect(): void {
		$botToken = $this->settingManager->getString('discord_bot_token');
		if (empty($botToken) || $botToken === 'off') {
			return;
		}
		$this->client = $this->websocket->createClient()
			->withURI("wss://gateway.discord.gg/?v=9&encoding=json")
			->withTimeout(30)
			->on(WebsocketClient::ON_CLOSE, [$this, "processWebsocketClose"])
			->on(WebsocketClient::ON_TEXT, [$this, "processWebsocketMessage"])
			->on(WebsocketClient::ON_ERROR, [$this, "processWebsocketError"]);
	}

	/**
	 * Send periodic heartbeats to the Discord gateway
	 */
	public function sendWebsocketHeartbeat(): void {
		if (!isset($this->heartbeatInterval)
			|| !$this->isConnected()
			|| !$this->client
			|| !$this->client->isConnected()
		) {
			return;
		}
		$this->lastHeartbeat = time();
		$this->client->send(json_encode(["op" => 1, "d" => $this->lastSequenceNumber]), "text");
		$this->logger->log("DEBUG", "Sending heartbeat");
		$this->timer->callLater($this->heartbeatInterval, [$this, __FUNCTION__]);
	}

	public function processWebsocketError(WebsocketCallback $event): void {
		$this->logger->log("ERROR", "[$event->code] $event->data");
		if ($event->code === WebsocketError::CONNECT_TIMEOUT) {
			$this->timer->callLater(30, [$this->client, 'connect']);
		}
	}

	public function processWebsocketMessage(WebsocketCallback $event): void {
		$payload = new Payload();
		try {
			$payload->fromJSON(json_decode($event->data, false, 512, JSON_THROW_ON_ERROR));
		} catch (JsonException $e) {
			$this->logger->log("ERROR", "Invalid JSON data received from Discord");
			$this->client->close(4002);
			return;
		}
		$this->logger->log("DEBUG", "Received packet op " . $payload->op);
		if (isset($payload->s)) {
			$this->lastSequenceNumber = $payload->s;
		}
		$eventObj = new DiscordGatewayEvent();
		$eventObj->type = "discord({$payload->op})";
		$eventObj->message = $event->data;
		$eventObj->payload = $payload;
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * @Event("discord(10)")
	 * @Description("Authorize to discord gateway")
	 * @DefaultStatus("1")
	 */
	public function processGatewayHello(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		$this->heartbeatInterval = intdiv($payload->d->heartbeat_interval, 1000);
		$this->timer->callLater($this->heartbeatInterval, [$this, "sendWebsocketHeartbeat"]);
		$this->logger->log('DEBUG', "Setting Discord heartbeat interval to ".$this->heartbeatInterval."sec");
		$this->lastHeartbeat = time();

		if ($this->sessionId !== null && $this->lastSequenceNumber !== null) {
			$this->sendResume();
		} else {
			$this->sendIdentify();
		}
	}

	protected function sendIdentify() {
		$this->logger->log("INFO", "Logging into Discord gateway");
		$identify = new IdentifyPacket();
		$identify->token = $this->settingManager->getString('discord_bot_token');
		$identify->intents = Intent::GUILD_MESSAGES
			| Intent::DIRECT_MESSAGES
			| Intent::GUILDS
			| Intent::GUILD_VOICE_STATES;
		$login = new Payload();
		$login->op = Opcode::IDENTIFY;
		$login->d = $identify;
		$this->client->send(json_encode($login));
	}

	protected function sendResume() {
		$this->logger->log("INFO", "Trying to resume old Discord gateway session");
		$resume = new ResumePacket();
		$resume->token = $this->settingManager->getString('discord_bot_token');
		$resume->session_id = $this->sessionId;
		$resume->seq = $this->lastSequenceNumber;
		$payload = new Payload();
		$payload->op = Opcode::RESUME;
		$payload->d = $resume;
		$this->client->send(json_encode($payload));
	}

	/**
	 * @Event("discord(0)")
	 * @Description("Handle discord gateway intents")
	 * @DefaultStatus("1")
	 */
	public function processGatewayEvents(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		if ($payload->t === null) {
			return;
		}
		$newEvent = new DiscordGatewayEvent();
		$newEvent->payload = $payload;
		$newEvent->type = strtolower("discord({$payload->t})");
		$this->logger->log("DEBUG", "New event: discord({$payload->t})");
		$this->eventManager->fireEvent($newEvent);
	}

	/**
	 * @Event("discord(7)")
	 * @Description("Reconnect to discord gateway if requested")
	 * @DefaultStatus("1")
	 */
	public function processGatewayReconnectRequest(DiscordGatewayEvent $event): void {
		$this->logger->log("DEBUG", "Discord Gateway requests reconnect");
		$this->mustReconnect = true;
		$this->reconnectDelay = 1;
		$this->client->close(1000);
	}

	/**
	 * @Event("discord(9)")
	 * @Description("Handle invalid session answers")
	 * @DefaultStatus("1")
	 */
	public function processGatewayInvalidSession(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		if ($payload->d === true) {
			$this->logger->log("DEBUG", "Session invalid, trying to resume");
			$this->sendResume();
			return;
		}
		$this->logger->log("DEBUG", "Session invalid, trying to start new one");
		$this->sendIdentify();
	}

	/**
	 * Check if a close code allowed reconnecting
	 * @param null|int $code The close code from the Discord server
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
				CloseEvents::SESSION_TIMED_OUT
			]
		);
	}

	public function processWebsocketClose(WebsocketCallback $event): void {
		if (!$this->canResumeSessionAfterClose($event->code ?? null)) {
			$this->lastSequenceNumber = null;
			$this->sessionId = null;
		}
		$this->guilds = [];
		if (
			(($event->code ?? null) === 1000 && $this->mustReconnect)
			|| $this->shouldReconnect($event->code ?? null)
		) {
			$this->logger->log("INFO", "Reconnecting to Discord gateway in {$this->reconnectDelay}s.");
			$this->mustReconnect = false;
			$this->timer->callLater($this->reconnectDelay, [$this->client, 'connect']);
			$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
		}
	}

	/**
	 * @Event("discord(message_create)")
	 * @Description("Handle discord gateway messages")
	 * @DefaultStatus("1")
	 */
	public function processDiscordMessage(DiscordGatewayEvent $event): void {
		$message = new DiscordMessageIn();
		$message->fromJSON($event->payload->d);
		if ($message->author->id === $this->me->id ?? null) {
			return;
		}

		$this->discordAPIClient->cacheUser($message->author);
		$name = $message->author->username . "#" . $message->author->discriminator;
		$this->idToName[$message->author->id] = $name;
		if (isset($message->member)) {
			$member = $message->member;
			$member->user ??= $message->author;
			$this->discordAPIClient->cacheGuildMember($message->guild_id, $member);
			$name = $message->member->nick ?? $name;
		}
		$channel = $this->getChannel($message->channel_id);
		$channelName = $channel ? ($channel->name??"DM") : "thread";
		if ($message->guild_id) {
			$this->logger->logChat("Discord:{$channelName}", $name, $message->content);
		} else {
			$this->logger->logChat("Inc. Discord Msg.", $name, $message->content);
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
		$this->resolveDiscordMentions(
			$message->guild_id??null,
			$text,
			function(string $text) use ($message, $channelName, $name, $member): void {
				$aoMessage = $text;
				$rMessage = new RoutableMessage($aoMessage);
				if ($message->guild_id) {
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
				$senderDisplayName = trim(preg_replace("/([\x{0450}-\x{fffff}])/u", "", $name));
				$rMessage->setCharacter(new Character($senderDisplayName));
				$this->messageHub->handle($rMessage);
			}
		);

		$event = new DiscordMessageEvent();
		$event->message = $message->content;
		$event->sender = $name;
		$event->type = $message->guild_id ? "discordpriv" : "discordmsg";
		$event->discord_message = $message;
		$event->channel = $message->channel_id;
		$this->eventManager->fireEvent($event);
	}

	public function embedToAOML(DiscordEmbed $embed): string {
		$blob = "";
		if (!empty($embed->description)) {
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
		if (!empty($embed->title)) {
			if (!empty($embed->url)) {
				$blob = "Details <a href='chatcmd:///start {$embed->url}'>here</a>\n\n".
					$blob;
			}
			$msg = $this->text->makeBlob(
				DiscordRelayController::formatMessage($embed->title),
				$blob
			);
		} else {
			$msg = $blob;
		}
		return $msg;
	}

	/**
	 * Recursively resolve all mentions in $message and then call $callback
	 */
	public function resolveDiscordMentions(?string $guildId, string $message, callable $callback): void {
		if (!preg_match("/(?:<|&lt;)@!?(\d+)(?:>|&gt;)/", $message, $matches)) {
			$callback($message);
			return;
		}
		$niceName = $this->discordGatewayCommandHandler->getNameForDiscordId($matches[1]);
		if (isset($niceName)) {
			$message = preg_replace("/(?:<|&lt;)@!?" . preg_quote($matches[1], "/") . "(?:>|&gt;)/", "@{$niceName}", $message);
			$this->resolveDiscordMentions($guildId, $message, $callback);
			return;
		}
		if (isset($guildId)) {
			$this->discordAPIClient->getGuildMember(
				$guildId,
				$matches[1],
				function(GuildMember $member, string $guildId, string $message, callable $callback) {
					$message = preg_replace("/(?:<|&lt;)@!?" . $member->user->id . "(?:>|&gt;)/", "@" . $member->getName(), $message);
					$this->resolveDiscordMentions($guildId, $message, $callback);
				},
				$guildId,
				$message,
				$callback
			);
			return;
		}
		$this->discordAPIClient->getUser(
			$matches[1],
			function(DiscordUser $user, ?int $guildId, string $message, callable $callback) {
				$message = preg_replace("/(?:<|&lt;)@!?" . $user->id . "(?:>|&gt;)/", "@{$user->username}", $message);
				$this->resolveDiscordMentions($guildId, $message, $callback);
			},
			$guildId,
			$message,
			$callback
		);
	}

	/**
	 * @Event("discord(guild_create)")
	 * @Event("discord(guild_update)")
	 * @Description("Handle discord guild changes")
	 * @DefaultStatus("1")
	 */
	public function processDiscordGuildMessages(DiscordGatewayEvent $event): void {
		$guild = new Guild();
		$guild->fromJSON($event->payload->d);
		$this->guilds[(string)$guild->id] = $guild;
		foreach ($guild->voice_states as $voiceState) {
			$this->discordAPIClient->getGuildMember(
				(string)$guild->id,
				$voiceState->user_id,
				function(GuildMember $member, VoiceState $voiceState) {
					$voiceState->member = $member;
				},
				$voiceState
			);
		}
		foreach ($guild->channels as $channel) {
			if ($channel->type !== $channel::GUILD_TEXT) {
				continue;
			}
			$dc = new RoutedChannel($channel->name, $channel->id);
			Registry::injectDependencies($dc);
			$this->messageHub
				->registerMessageReceiver($dc)
				->registerMessageEmitter($dc);
		}
	}

	/**
	 * @Event("discord(channel_create)")
	 * @Event("discord(channel_update)")
	 * @Event("discord(channel_delete)")
	 * @Description("Handle discord channel changes")
	 * @DefaultStatus("1")
	 */
	public function processDiscordChannelMessages(DiscordGatewayEvent $event): void {
		$channel = new DiscordChannel();
		$channel->fromJSON($event->payload->d);
		// Not a guild-channel? Must be a DM channel which we don't cache anyway
		if (!isset($channel->guild_id)) {
			return;
		}
		if (!isset($this->guilds[$channel->guild_id])) {
			$this->logger->log("ERROR", "Received channel info for unknown guild");
			return;
		}
		$channels = &$this->guilds[$channel->guild_id]->channels;
		if ($event->payload->t === "CHANNEL_CREATE") {
			$channels []= $channel;
			if ($channel->type !== $channel::GUILD_TEXT) {
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
					function(DiscordChannel $c) use ($channel) {
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

			$dc = new RoutedChannel($channel->name, $channel->id);
			Registry::injectDependencies($dc);
			$this->messageHub
				->registerMessageReceiver($dc)
				->registerMessageEmitter($dc);
			return;
		}
	}

	/**
	 * @Event("discord(ready)")
	 * @Description("Handle discord READY event")
	 * @DefaultStatus("1")
	 */
	public function processDiscordReady(DiscordGatewayEvent $event): void {
		$payload = $event->payload;
		$this->sessionId = $payload->d->session_id;
		$user = new DiscordUser();
		$user->fromJSON($payload->d->user);
		$this->me = $user;
		$this->logger->log(
			'INFO',
			"Successfully logged into Discord Gateway as ".
			$user->username . "#" . $user->discriminator
		);
		$this->reconnectDelay = 5;
	}

	/**
	 * @Event("discord(resumed)")
	 * @Description("Handle discord RESUMED event")
	 * @DefaultStatus("1")
	 */
	public function processDiscordResumed(DiscordGatewayEvent $event): void {
		$this->logger->log(
			'INFO',
			"Session successfully resumed as ".
			$this->me->username . "#" . $this->me->discriminator
		);
	}

	/**
	 * @Event("discord(voice_state_update)")
	 * @Description("Keep track of people in the voice chat")
	 * @DefaultStatus("1")
	 */
	public function trackVoiceStateChanges(Event $event) {
		$payload = $event->payload;
		$voiceState = new VoiceState();
		$voiceState->fromJSON($payload->d);
		if (!isset($voiceState->channel_id)) {
			$this->handleVoiceChannelLeave($voiceState);
		} else {
			$this->handleVoiceChannelJoin($voiceState);
		}
	}

	/**
	 * Remove a Discord UserId from all voice channels
	 */
	protected function removeFromVoice(string $userId): ?VoiceState {
		$oldState = $this->getCurrentVoiceState($userId);
		if ($oldState === null) {
			return null;
		}
		$this->guilds[$oldState->guild_id]->voice_states = array_values(
			array_filter(
				$this->guilds[$oldState->guild_id]->voice_states ?? [],
				function (VoiceState $state) use ($oldState) {
					return $state->user_id !== $oldState->user_id;
				}
			)
		);
		return $oldState;
	}

	protected function handleVoiceChannelLeave(VoiceState $voiceState): void {
		$oldState = $this->removeFromVoice($voiceState->user_id);
		if ($oldState === null) {
			return;
		}
		$channel = $this->getChannel($oldState->channel_id);
		$this->discordAPIClient->getGuildMember(
			$channel->guild_id,
			$voiceState->user_id,
			function (GuildMember $member) use ($oldState) {
				$event = new DiscordVoiceEvent();
				$event->type = "discord_voice_leave";
				$event->discord_channel = $this->getChannel($oldState->channel_id);
				$event->member = $member;
				$this->eventManager->fireEvent($event);
			}
		);
	}

	/**
	 * @return array<string,array<string,array<string>>>
	 */
	public function getPlayersInVoiceChannels(): array {
		$channels = [];
		foreach ($this->guilds as $guildId => $guild) {
			foreach ($guild->voice_states as $voiceState) {
				$channel = $this->getChannel($voiceState->channel_id);
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

	protected function handleVoiceChannelJoin(VoiceState $voiceState): void {
		$this->removeFromVoice($voiceState->user_id);
		if (!isset($voiceState->guild_id)) {
			return;
		}
		$this->guilds[$voiceState->guild_id]->voice_states []= $voiceState;
		$this->lookupChannel(
			$voiceState->channel_id,
			[$this, "handleAsyncVoiceChannelJoin"],
			$voiceState
		);
	}

	public function handleAsyncVoiceChannelJoin(DiscordChannel $channel, VoiceState $voiceState): void {
		$this->discordAPIClient->getGuildMember(
			$voiceState->guild_id,
			$voiceState->user_id,
			function (GuildMember $member, DiscordChannel $channel) {
				$event = new DiscordVoiceEvent();
				$event->type = "discord_voice_join";
				$event->discord_channel = $channel;
				$event->member = $member;
				$this->eventManager->fireEvent($event);
			},
			$channel
		);
	}

	/**
	 * @Event("discord_voice_leave")
	 * @Event("discord_voice_join")
	 * @Description("Announce if people join or leave voice chat")
	 */
	public function announceVoiceStateChange(DiscordVoiceEvent $event): void {
		$showChanges = $this->settingManager->getInt('discord_notify_voice_changes');
		if ($showChanges === 0) {
			return;
		}
		if ($event->type === 'discord_voice_leave') {
			$msg = $event->member->getName().
				" has left the voice channel <highlight>".
				$event->discord_channel->name.
				"<end>.";
		} else {
			$msg = $event->member->getName().
				" has entered the voice channel <highlight>".
				$event->discord_channel->name.
				"<end>.";
		}
		if ($showChanges & 1) {
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($showChanges & 2) {
			$this->chatBot->sendGuild($msg, true);
		}
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
}
