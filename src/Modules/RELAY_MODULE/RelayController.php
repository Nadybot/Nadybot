<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Exception;
use Illuminate\Support\Collection;
use JsonException;
use Nadybot\Core\{
	AMQP,
	AMQPExchange,
	AOChatEvent,
	ClassSpec,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Websocket,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Registry,
	Timer,
	WebsocketCallback,
	WebsocketClient,
	WebsocketError,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\GUILD_MODULE\GuildController;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use ReflectionMethod;
use Throwable;

/**
 * @author Tyrence
 * @author Nadyita
 *
 * @Instance
 *
 * Commands this controller contains:
 *  @DefineCommand(
 *		command     = 'grc',
 *		accessLevel = 'all',
 *		description = 'Relays incoming messages to guildchat'
 *	)
 *  @DefineCommand(
 *		command     = 'gcr',
 *		accessLevel = 'all',
 *		description = 'Relays incoming bebot messages to guildchat'
 *	)
 *  @DefineCommand(
 *		command     = 'relay',
 *		accessLevel = 'mod',
 *		description = 'Setup and modify relays between bots',
 *		help        = 'relay.txt'
 *	)
 *  @ProvidesEvent("routable(message)")
 */
class RelayController {
	public const TYPE_AMQP = 3;
	public const TYPE_TYRWS = 4;

	public const DB_TABLE = 'relay_<myname>';
	public const DB_TABLE_LAYER = 'relay_layer_<myname>';
	public const DB_TABLE_ARGUMENT = 'relay_layer_argument_<myname>';

	/** @var array<string,ClassSpec> */
	protected array $relayProtocols = [];

	/** @var array<string,ClassSpec> */
	protected array $transports = [];

	/** @var array<string,ClassSpec> */
	protected array $stackElements = [];

	/** @var array<string,Relay> */
	protected array $relays = [];

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Preferences $preferences;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public AMQP $amqp;

	/** @Inject */
	public Websocket $websocket;

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public WebsocketClient $tyrClient;

	/** @Inject */
	public Timer $timer;

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->settingManager->add(
			$this->moduleName,
			'relaytype',
			"Type of relay",
			"edit",
			"options",
			"1",
			"tell;private channel;amqp;Tyrbot Websocket",
			'1;2;3;4'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relaysymbol',
			"Symbol for external relay",
			"edit",
			"options",
			"@",
			"!;#;*;@;$;+;-"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_symbol_method',
			"When to relay messages",
			"edit",
			"options",
			"0",
			"Always relay;Relay when symbol;Relay unless symbol",
			'0;1;2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relaybot',
			"Bot/AMQP exchange/Websocket URL for Guildrelay",
			"edit",
			"text",
			"Off",
			"Off",
			'',
			"mod",
			"relaybot.txt"
		);
		$this->settingManager->add(
			$this->moduleName,
			'bot_relay_commands',
			"Relay commands and results over the bot relay",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_color_guild',
			"Color of messages from relay to guild channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_color_priv',
			"Color of messages from relay to private channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_abbreviation',
			'Abbreviation to use for org name',
			'edit',
			'text',
			'none',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_ignore',
			'Semicolon-separated list of people not to relay away',
			'edit',
			'text',
			'',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_filter_out',
			'RegExp filtering outgoing messages',
			'edit',
			'text',
			'',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_filter_in',
			'RegExp filtering messages to org chat',
			'edit',
			'text',
			'',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_filter_in_priv',
			'RegExp filtering messages to priv chat',
			'edit',
			'text',
			'',
			'none'
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_bot_color_org',
			"Color of bot messages from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_bot_color_priv',
			"Color of bot messages from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_tag_color_org',
			"Color of the guild name tag from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_tag_color_priv',
			"Color of the guild name tag from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_color_org',
			"Color of the org chat from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_color_priv',
			"Color of the org chat from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guest_tag_color_org',
			"Color of the [Guest] tag from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guest_tag_color_priv',
			"Color of the [Guest] tag from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guest_color_org',
			"Color of the guest channel messages from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_guest_color_priv',
			"Color of the guest channel messages from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_raidbot_tag_color_org',
			"Color of the raidboot name tag from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_raidbot_tag_color_priv',
			"Color of the raidboot name tag from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_raidbot_color_org',
			"Color of the raidboot chat from relay to org channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);
		$this->settingManager->add(
			$this->moduleName,
			'relay_raidbot_color_priv',
			"Color of the raidboot chat from relay to priv channel",
			'edit',
			"color",
			"<font color='#C3C3C3'>"
		);

		$this->commandAlias->register(
			$this->moduleName,
			"macro settings save relaytype 1|settings save relaysymbol Always relay|settings save relaybot",
			"tellrelay"
		);
		$relayBot = $this->settingManager->getString('relaybot');
		$relayType = $this->settingManager->getInt('relaytype');
		if ($relayType === self::TYPE_AMQP && $relayBot !== 'Off') {
			foreach (explode(",", $relayBot) as $exchange) {
				$exchObject = new AMQPExchange();
				$exchObject->name = $exchange;
				$exchObject->type = AMQPExchangeType::FANOUT;
				$this->amqp->connectExchange($exchObject);
			}
		}
		if ($relayType === self::TYPE_TYRWS && $relayBot !== 'Off') {
			$this->connectTyrWs();
		}
		$this->settingManager->registerChangeListener(
			'relaybot',
			[$this, 'relayBotChanges']
		);
		$this->settingManager->registerChangeListener(
			'relaytype',
			[$this, 'relayTypeChanges']
		);
		$this->timer->callLater(10, function() {
			$this->logger->log("INFO", "Relaying...");
			$event = new AOChatEvent();
			$event->sender = "Pigtail";
			$event->message = "Hey there";
			$event->type = "priv";
			// $this->eventManager->fireEvent($event);
		});
		$this->loadStackComponents();
	}

	/**
	 * @Event("connect")
	 * @Description("Load relays from database")
	 */
	public function loadRelays() {
		$relays = $this->getRelays();
		foreach ($relays as $relayConf) {
			try {
				$relay = $this->createRelayFromDB($relayConf);
				$this->addRelay($relay);
				$relay->init(function() use ($relay) {
					$this->logger->log('INFO', "Relay " . $relay->getName() . " initialized");
				});
			} catch (Exception $e) {
				$this->logger->log('ERROR', $e->getMessage(), $e);
			}
		}
	}

	public function loadStackComponents(): void {
		$types = [
			"RelayProtocol" => [
				"RelayProtocol",
				[$this, "registerRelayProtocol"],
			],
			"Layer" => [
				"RelayStackMember",
				[$this, "registerStackElement"],
			],
			"Transport" => [
				"RelayTransport",
				[$this, "registerTransport"],
			]
		];
		foreach ($types as $dir => $data) {
			$files = glob(__DIR__ . "/{$dir}/*.php");
			foreach ($files as $file) {
				require_once $file;
				$className = basename($file, ".php");
				$fullClass = __NAMESPACE__ . "\\{$dir}\\{$className}";
				$spec = $this->util->getClassSpecFromClass($fullClass, $data[0]);
				if (isset($spec)) {
					$data[1]($spec);
				}
			}
		}
	}

	public function registerRelayProtocol(ClassSpec $proto): bool {
		$this->relayProtocols[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerTransport(ClassSpec $proto): bool {
		$this->transports[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerStackElement(ClassSpec $proto): bool {
		$this->stackElements[strtolower($proto->name)] = $proto;
		return true;
	}

	public function connectTyrWs(): void {
		$relayBot = $this->settingManager->getString('relaybot');
		$this->logger->log('INFO', "Connecting to Tyrbot relay {$relayBot}.");
		$this->tyrClient = $this->websocket->createClient()
			->withURI($relayBot)
			->withTimeout(30)
			->on(WebsocketClient::ON_CLOSE, [$this, "processTyrRelayClose"])
			->on(WebsocketClient::ON_TEXT, [$this, "processTyrRelayMessage"])
			->on(WebsocketClient::ON_ERROR, [$this, "processTyrRelayError"]);
	}

	/**
	 * When the relaytype changes, switch properly
	 */
	public function relayTypeChanges(string $setting, string $oldValue, string $newValue, $extraData): void {
		$relayBot = $this->settingManager->getString('relaybot');
		if ($relayBot === 'Off') {
			return;
		}

		$amqp = (string)self::TYPE_AMQP;
		if ($oldValue === $amqp || $newValue === $amqp) {
			$exchanges = array_values(array_diff(explode(",", $relayBot), ["Off"]));
			if ($oldValue === $amqp) {
				foreach ($exchanges as $unsub) {
					$this->amqp->disconnectExchange($unsub);
				}
				return;
			}
			foreach ($exchanges as $sub) {
				$exchObject = new AMQPExchange();
				$exchObject->name = $sub;
				$exchObject->type = AMQPExchangeType::FANOUT;
				$this->amqp->connectExchange($exchObject);
			}
		}

		$tyrws = (string)self::TYPE_TYRWS;
		if ($oldValue === $tyrws || $newValue === $tyrws) {
			if ($oldValue === $tyrws) {
				$this->tyrClient->close();
				return;
			}
			$this->connectTyrWs();
		}
	}

	/**
	 * When the relaybot changes for AMQP relays, (un)subscribe from the exchanges
	 */
	public function relayBotChanges(string $setting, string $oldValue, string $newValue, $extraData) {
		if ($this->settingManager->getInt('relaytype') !== 3) {
			return;
		}
		$oldExchanges = explode(",", $oldValue);
		$newExchanges = explode(",", $newValue);
		if ($newValue === 'Off') {
			$newExchanges = [];
		}

		foreach (array_values(array_diff($oldExchanges, $newExchanges)) as $unsub) {
			$this->amqp->disconnectExchange($unsub);
		}
		foreach (array_values(array_diff($newExchanges, $oldExchanges)) as $sub) {
			$exchObject = new AMQPExchange();
			$exchObject->name = $sub;
			$exchObject->type = AMQPExchangeType::FANOUT;
			$this->amqp->connectExchange($exchObject);
		}
	}

	/**
	 * @HandlesCommand("grc")
	 */
	public function grcCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->processIncomingRelayMessage($sender, $message);
	}

	/**
	 * @HandlesCommand("gcr")
	 */
	public function gcrCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->processIncomingRelayMessage($sender, $message);
	}

	/**
	 * @Event("amqp")
	 * @Description("Receive relay messages from other bots via AMQP")
	 */
	public function receiveRelayMessageAMQP(Event $eventObj): void {
		$this->processIncomingRelayMessage($eventObj->channel, $eventObj->message);
	}

	/**
	 * @Event("extPriv")
	 * @Description("Receive relay messages from other bots in the relay bot private channel")
	 */
	public function receiveRelayMessageExtPrivEvent(Event $eventObj): void {
		$this->processIncomingRelayMessage($eventObj->channel, $eventObj->message);
	}

	/**
	 * @Event("priv")
	 * @Description("Receive relay messages from other bots in this bot's own private channel")
	 */
	public function receiveRelayMessagePrivEvent(Event $eventObj): void {
		$this->processIncomingRelayMessage($eventObj->sender, $eventObj->message);
	}

	/**
	 * Parse and replace BeBot-style color-codes (##red##) with their actual colors (<font>)
	 */
	public function replaceBeBotColors(string $text): string {
		$colors = [
			"aqua"         => "#00FFFF",
			"beige"        => "#FFE3A1",
			"black"        => "#000000",
			"blue"         => "#0000FF",
			"bluegray"     => "#8CB6FF",
			"bluesilver"   => "#9AD5D9",
			"brown"        => "#999926",
			"darkaqua"     => "#2299FF",
			"darklime"     => "#00A651",
			"darkorange"   => "#DF6718",
			"darkpink"     => "#FF0099",
			"forestgreen"  => "#66AA66",
			"fuchsia"      => "#FF00FF",
			"gold"         => "#CCAA44",
			"gray"         => "#808080",
			"green"        => "#008000",
			"lightbeige"   => "#FFFFC9",
			"lightfuchsia" => "#FF63FF",
			"lightgray"    => "#D9D9D2",
			"lightgreen"   => "#00DD44",
			"brightgreen"  => "#00F000",
			"lightmaroon"  => "#FF0040",
			"lightteal"    => "#15E0A0",
			"dullteal"     => "#30D2FF",
			"lightyellow"  => "#DEDE42",
			"lime"         => "#00FF00",
			"maroon"       => "#800000",
			"navy"         => "#000080",
			"olive"        => "#808000",
			"orange"       => "#FF7718",
			"pink"         => "#FF8CFC",
			"purple"       => "#800080",
			"red"          => "#FF0000",
			"redpink"      => "#FF61A6",
			"seablue"      => "#6699FF",
			"seagreen"     => "#66FF99",
			"silver"       => "#C0C0C0",
			"tan"          => "#DDDD44",
			"teal"         => "#008080",
			"white"        => "#FFFFFF",
			"yellow"       => "#FFFF00",
			"omni"         => "#00FFFF",
			"clan"         => "#FF9933",
			"neutral"      => "#FFFFFF",
		];
		$hlColor = $this->settingManager->getString('default_highlight_color');
		if (preg_match("/(#[A-F0-9]{6})/i", $hlColor, $matches)) {
			$colors["highlight"] = $matches[1];
		}

		$colorAliases = [
			"admin"          => "pink",
			"cash"           => "gold",
			"ccheader"       => "white",
			"cctext"         => "lightgray",
			"clan"           => "brightgreen",
			"emote"          => "darkpink",
			"error"          => "red",
			"feedback"       => "yellow",
			"gm"             => "redpink",
			"infoheader"     => "lightgreen",
			"infoheadline"   => "tan",
			"infotext"       => "forestgreen",
			"infotextbold"   => "white",
			"megotxp"        => "yellow",
			"meheald"        => "bluegray",
			"mehitbynano"    => "white",
			"mehitother"     => "lightgray",
			"menubar"        => "lightteal",
			"misc"           => "white",
			"monsterhitme"   => "red",
			"mypet"          => "orange",
			"newbie"         => "seagreen",
			"news"           => "brightgreen",
			"none"           => "fuchsia",
			"npcchat"        => "bluesilver",
			"npcdescription" => "yellow",
			"npcemote"       => "lightbeige",
			"npcooc"         => "lightbeige",
			"npcquestion"    => "lightgreen",
			"npcsystem"      => "red",
			"npctrade"       => "lightbeige",
			"otherhitbynano" => "bluesilver",
			"otherpet"       => "darkorange",
			"pgroup"         => "white",
			"playerhitme"    => "red",
			"seekingteam"    => "seablue",
			"shout"          => "lightbeige",
			"skillcolor"     => "beige",
			"system"         => "white",
			"team"           => "seagreen",
			"tell"           => "aqua",
			"tooltip"        => "black",
			"tower"          => "lightfuchsia",
			"vicinity"       => "lightyellow",
			"whisper"        => "dullteal",
		];
		$colorizedText = preg_replace_callback(
			"/##([a-zA-Z]+)##/",
			function (array $matches) use ($colorAliases, $colors): string {
				$color = strtolower($matches[1]);
				if (isset($colorAliases[$color])) {
					$color = $colorAliases[$color];
				}
				if (isset($colors[$color])) {
					return "<font color={$colors[$color]}>";
				} elseif ($color === "end") {
					return "</font>";
				}
				return $matches[0];
			},
			$text
		);
		return $colorizedText;
	}

	/**
	 * Replace the color-tags of relay messages with their actual colors
	 * depending on where we relay into (org or priv)
	 */
	public function replaceRelayColors(string $channel, string $text): string {
		$colors = [
			"relay_bot_color",
			"relay_guild_tag_color",
			"relay_guild_color",
			"relay_guest_tag_color",
			"relay_guest_color",
			"relay_raidbot_tag_color",
			"relay_raidbot_color",

		];
		if (strpos($text, "##relay_channel##") !== false) {
			// BeBot relay
			$text = preg_replace("/##relay_channel##\[(.*?)\]##end##/", "<relay_guild_tag_color>[$1]</font>", $text);
			$text = preg_replace("/\[##relay_channel##(.*?)##end##\]/", "<relay_guild_tag_color>[$1]</font>", $text);
			$text = preg_replace("/##relay_message##(.*)##end##$/", "$1", $text);
			$text = preg_replace("/##logon_logo(n|ff)_spam##/", "<relay_bot_color>", $text);
			$text = preg_replace("/##logon_ailevel##(.*?)##end##/", "<font color=#00DE42>$1<font>", $text);
			$text = preg_replace("/##logon_organization##(.*?)##end##/", "$1", $text);
			$text = preg_replace(
				"/##(?:relay_mainname|logon_level)##(.+?)##end##/",
				$this->settingManager->getString('default_highlight_color') . "$1</font>",
				$text
			);
			$text = preg_replace(
				"/##relay_name##([a-zA-Z0-9_-]+)(.*?)##end##/",
				"<a href=user://$1>$1</a>$2",
				$text
			);
			$text = "<v2>" . $this->replaceBeBotColors($text);
		}
		if (substr($text, 0, 4) === "<v2>") {
			$text = str_replace("</end>", "</font>", $text);
			return preg_replace_callback(
				"/<(" . join("|", $colors) . ")>/",
				function (array $matches) use ($channel): string {
					return $this->settingManager->getString($matches[1] . "_{$channel}");
				},
				substr($text, 4)
			);
		}
		if ($channel === "org") {
			return $this->settingManager->getString('relay_color_guild') . $text;
		} else {
			return $this->settingManager->getString('relay_color_priv') . $text;
		}
	}

	public function processIncomingRelayMessage(string $sender, string $message): void {
		if (/*!in_array(strtolower($sender), explode(",", strtolower($this->settingManager->getString('relaybot'))))
			||*/ !preg_match("/^(?:grc|gcr) (.+)$/s", $message, $arr)) {
			return;
		}
		$msg = $arr[1];
		if (!$this->matchesFilter($this->settingManager->getString('relay_filter_in'), $message)) {
			$this->chatBot->sendGuild(
				$this->replaceRelayColors("org", $msg),
				true
			);
		}

		if ($this->settingManager->getBool("guest_relay")) {
			if (!$this->matchesFilter($this->settingManager->getString('relay_filter_in_priv'), $message)) {
				$this->chatBot->sendPrivate(
					$this->replaceRelayColors("priv", $msg),
					true
				);
			}
		}
	}

	/**
	 * @Event("guild")
	 * @Description("Sends org chat to relay")
	 */
	public function orgChatToRelayEvent(AOChatEvent $eventObj): void {
		$relayMsg = new RoutableMessage($eventObj->message);
		$char = new Character($eventObj->sender, $this->chatBot->get_uid($eventObj->sender));
		$relayMsg->setCharacter($char);
		$this->eventManager->fireEvent($relayMsg);
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * @Event("priv")
	 * @Description("Sends private channel chat to relay")
	 */
	public function privChatToRelayEvent(AOChatEvent $eventObj): void {
		$relayMsg = new RoutableMessage($eventObj->message);
		$char = new Character($eventObj->sender, $this->chatBot->get_uid($eventObj->sender));
		$relayMsg->setCharacter($char);
		if (strlen($this->chatBot->vars["my_guild"])) {
			$source = new Source(Source::PRIV, "Guest");
			$relayMsg->prependPath($source);
		}
		$this->eventManager->fireEvent($relayMsg);
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * @Event("sendpriv")
	 * @Description("Sends bot's private channel chat to relay")
	 */
	public function privBotChatToRelayEvent(AOChatEvent $eventObj, bool $disableRelay): void {
		if ($disableRelay) {
			return;
		}
		$relayMsg = new RoutableMessage($eventObj->message);
		$char = new Character($eventObj->sender, $this->chatBot->get_uid($eventObj->sender));
		$relayMsg->setCharacter($char);
		if (strlen($this->chatBot->vars["my_guild"])) {
			$source = new Source(Source::PRIV, $this->chatBot->vars["name"], "Guest");
			$relayMsg->prependPath($source);
		}
		$this->eventManager->fireEvent($relayMsg);
	}

	/**
	 * @Event("sendguild")
	 * @Description("Sends bot's org channel chat to relay")
	 */
	public function guildBotChatToRelayEvent(AOChatEvent $eventObj, bool $disableRelay): void {
		if ($disableRelay) {
			return;
		}
		$relayMsg = new RoutableMessage($eventObj->message);
		$char = new Character($eventObj->sender, $this->chatBot->get_uid($eventObj->sender));
		$relayMsg->setCharacter($char);
		$this->eventManager->fireEvent($relayMsg);
	}

	public function addMainHop(RoutableEvent $event): RoutableEvent {
		$result = clone $event;
		if (strlen($this->chatBot->vars["my_guild"])) {
			$source = new Source(Source::ORG, $this->chatBot->vars["my_guild"], $this->getGuildAbbreviation());
		} else {
			$source = new Source(Source::PRIV, $this->chatBot->vars["name"]);
		}
		$result->prependPath($source);
		return $result;
	}

	/**
	 * @Event("routable(*)")
	 * @Description("Route events and messages between relays")
	 */
	public function routableHub(RoutableEvent $eventObj): void {
		$event = $this->addMainHop($eventObj);
		$proto = new \Nadybot\Modules\RELAY_MODULE\RelayProtocol\GrcV2Protocol();
		// var_dump($proto->parse($proto->render($event)));
	}

	/**
	 * Check if a message by a sender should not be relayed due to filters
	 *
	 * @param string $sender Name of the person sending the message
	 * @param string $message The message that wants to be relayed
	 * @return bool
	 */
	public function isFilteredMessage($sender, string $message): bool {
		$toIgnore = array_diff(
			explode(";", strtolower($this->settingManager->getString('relay_ignore'))),
			[""]
		);
		if (in_array(strtolower((string)$sender), $toIgnore)) {
			return true;
		}
		return $this->matchesFilter(
			$this->settingManager->getString('relay_filter_out'),
			$message
		);
	}

	/**
	 * Checks if a message matches a filter
	 */
	public function matchesFilter(string $filter, string $message): bool {
		if (!strlen($filter)) {
			return false;
		}
		$escapedFilter = str_replace("/", "\\/", $filter);
		return (bool)@preg_match("/$escapedFilter/", $message);
	}

	public function processOutgoingRelayMessage($sender, string $message, string $type): void {
		if ($this->settingManager->getString("relaybot") === "Off") {
			return;
		}
		// Don't relay commands if bot_relay_commands is turned off
		if (!$this->settingManager->getBool("bot_relay_commands")
			&& $message[0] === $this->settingManager->getString("symbol")) {
			return;
		}
		if ($this->isFilteredMessage($sender, $message)) {
			return;
		}
		$relayMessage = '';
		if ($this->settingManager->getInt('relay_symbol_method') === 0) {
			$relayMessage = $message;
		} elseif ($this->settingManager->getInt('relay_symbol_method') === 1
			&& $message[0] === $this->settingManager->getString('relaysymbol')
		) {
			$relayMessage = substr($message, 1);
		} elseif ($this->settingManager->get('relay_symbol_method') === 2
			&& $message[0] !== $this->settingManager->getString('relaysymbol')
		) {
			$relayMessage = $message;
		} else {
			return;
		}
		if ($this->settingManager->getInt('relaytype') === self::TYPE_TYRWS) {
			$tyrSender = ["name" => (string)$sender];
			if ($this->util->isValidSender($sender)) {
				$tyrSender["id"] = $this->chatBot->get_uid($sender);
				if (!is_int($tyrSender["id"])) {
					unset($tyrSender["id"]);
				}
			}
			$channel = ($type === "guild")
				? $this->chatBot->vars["my_guild"]
				: ((strlen($this->chatBot->vars["my_guild"]??""))
					? $this->chatBot->vars["my_guild"] . " Guest"
					: $this->chatBot->vars["name"]);
			$tyrMsg = (object)[
				"type" => "message",
				"sender" => (object)$tyrSender,
				"channel" => $channel,
				"message" => $message,
			];
			$this->tyrClient->send(json_encode($tyrMsg));
			return;
		}

		if (!$this->util->isValidSender($sender)) {
			$sender_link = '<relay_bot_color>';
		} else {
			$sender_link = ' ' . $this->text->makeUserlink($sender) . ':';
		}

		if ($type === "guild") {
			$msg = "grc <v2><relay_guild_tag_color>[<myguild>]</end>{$sender_link} ";
			if ($this->util->isValidSender($sender)) {
				$msg .= "<relay_guild_color>";
			}
			$msg .= "{$relayMessage}</end>";
		} elseif ($type === "priv") {
			if (strlen($this->chatBot->vars["my_guild"])) {
				$msg = "grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_guest_tag_color>[Guest]</end>{$sender_link} ";
				if ($this->util->isValidSender($sender)) {
					$msg .= "<relay_guest_color>";
				}
				$msg .= "{$relayMessage}</end>";
			} else {
				$msg = "grc <v2><relay_raidbot_tag_color>[<myname>]</end>{$sender_link} ";
				if ($this->util->isValidSender($sender)) {
					$msg .= "<relay_raidbot_color>";
				}
				$msg .= "{$relayMessage}</end>";
			}
		} else {
			$this->logger->log('WARN', "Invalid type; expecting 'guild' or 'priv'.  Actual: '$type'");
			return;
		}
		$this->sendMessageToRelay($msg);
	}

	/**
	 * @Event("extJoinPrivRequest")
	 * @Description("Accept private channel join invitation from the relay bot")
	 */
	public function acceptPrivJoinEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->getInt("relaytype") === 2
			&& strtolower($sender) == strtolower($this->settingManager->getString("relaybot"))
		) {
			$this->chatBot->privategroup_join($sender);
		}
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Relay Org Messages")
	 */
	public function relayOrgMessagesEvent(Event $eventObj): void {
		if ($this->settingManager->getString("relaybot") === "Off") {
			return;
		}
		$msg = "grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_bot_color>{$eventObj->message}</end><end>";
		$this->sendMessageToRelay($msg);
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends Logon messages over the relay")
	 */
	public function relayLogonMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->get("relaybot") === "Off"
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()) {
			return;
		}
		$this->guildController->getLogonMessageAsync($sender, false, function(string $msg): void {
			if (strlen($this->chatBot->vars["my_guild"])) {
				$this->sendMessageToRelay("grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_bot_color>".$msg);
			} else {
				$this->sendMessageToRelay("grc <v2><relay_raidbot_tag_color>[<myname>]</end> <relay_bot_color>".$msg);
			}
		});
	}

	/**
	 * @Event("logOff")
	 * @Description("Sends Logoff messages over the relay")
	 */
	public function relayLogoffMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->get("relaybot") === "Off"
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !$this->chatBot->isReady()
		) {
			return;
		}
		$msg = $this->guildController->getLogoffMessage($sender);
		if ($msg === null) {
			return;
		}
		if (strlen($this->chatBot->vars["my_guild"])) {
			$this->sendMessageToRelay("grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_bot_color>{$msg}</end>");
		} else {
			$this->sendMessageToRelay("grc <v2><relay_raidbot_tag_color>[<myname>]</end> <relay_bot_color>{$msg}</end>");
		}
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends a message to the relay when someone joins the private channel")
	 */
	public function relayJoinPrivMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->get('relaybot') === 'Off') {
			return;
		}
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($sender): void {
				$this->relayJoinMessage($player, $sender);
			},
			$sender
		);
	}

	protected function relayJoinMessage(?Player $whois, string $sender): void {
		$altInfo = $this->altsController->getAltInfo($sender);

		if ($whois !== null) {
			$msg = $this->playerManager->getInfo($whois) . " has joined the private channel.";
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				$altInfo->getAltsBlobAsync(
					function($blob) use ($msg): void {
						$this->relayMsgFromPriv("{$msg} {$blob}");
					},
					true
				);
				return;
			}
		} else {
			$msg = "$sender has joined the private channel.";
			if (count($altInfo->getAllValidatedAlts()) > 0) {
				$altInfo->getAltsBlobAsync(
					function($blob) use ($msg): void {
						$this->relayMsgFromPriv("{$msg} {$blob}");
					},
					true
				);
				return;
			}
		}
		$this->relayMsgFromPriv($msg);
	}

	public function relayMsgFromPriv(string $msg): void {
		if (strlen($this->chatBot->vars["my_guild"])) {
			$this->sendMessageToRelay("grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_bot_color>" . $msg);
		} else {
			$this->sendMessageToRelay("grc <v2><relay_raidbot_tag_color>[<myname>]</end> <relay_bot_color>" . $msg);
		}
	}

	/**
	 * @Event("leavePriv")
	 * @Description("Sends a message to the relay when someone leaves the private channel")
	 */
	public function relayLeavePrivMessagesEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->get('relaybot') === 'Off') {
			return;
		}
		$msg = "<highlight>{$sender}<end> has left the private channel.";
		if (strlen($this->chatBot->vars["my_guild"])) {
			$this->sendMessageToRelay("grc <v2><relay_guild_tag_color>[<myguild>]</end> <relay_bot_color>" . $msg);
		} else {
			$this->sendMessageToRelay("grc <v2><relay_raidbot_tag_color>[<myname>]</end> <relay_bot_color>" . $msg);
		}
	}

	public function sendMessageToRelay(string $message): void {
		$relayBot = $this->settingManager->getString('relaybot');
		$message = str_ireplace("<myguild>", $this->getGuildAbbreviation(), $message);

		// since we are using the aochat methods, we have to call formatMessage manually to handle colors and bot name replacement
		$message = $this->text->formatMessage($message);

		// we use the aochat methods so the bot doesn't prepend default colors
		if ($this->settingManager->getInt('relaytype') === 2) {
			$this->chatBot->send_privgroup($relayBot, $message);
		} elseif ($this->settingManager->getInt('relaytype') === 3) {
			foreach (explode(",", $relayBot) as $exchange) {
				$this->amqp->sendMessage($exchange, $message);
			}
		} elseif ($this->settingManager->getInt('relaytype') === 1) {
			foreach (explode(",", $relayBot) as $recipient) {
				$this->chatBot->send_tell($recipient, $message);

				// manual logging is only needed for tell relay
				$this->logger->logChat("Out. Msg.", $recipient, $message);
			}
		}
	}

	public function getGuildAbbreviation(): string {
		if ($this->settingManager->getString('relay_guild_abbreviation') !== 'none') {
			return $this->settingManager->getString('relay_guild_abbreviation');
		} else {
			return $this->chatBot->vars["my_guild"];
		}
	}

	public function processTyrRelayMessage(WebsocketCallback $event): void {
		try {
			$data = json_decode($event->data, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log("ERROR", "Invalid JSON data received from Websocket");
			$this->tyrClient->close(4002);
			return;
		}
		if (!isset($data->type)) {
			return;
		}
		if ($data->type === "message") {
			$senderLink = $this->text->makeUserlink($data->sender->name);
			$message = "grc <v2><relay_guild_tag_color>[{$data->channel}]</end> ".
				$senderLink . ": <relay_bot_color>{$data->message}</end>";
			$this->processIncomingRelayMessage(
				$data->sender->name,
				$message
			);
		}
	}

	public function processTyrRelayError(WebsocketCallback $event): void {
		$this->logger->log("ERROR", "[$event->code] $event->data");
		if ($event->code === WebsocketError::CONNECT_TIMEOUT) {
			$this->timer->callLater(30, [$this->tyrClient, 'connect']);
		}
	}

	public function processTyrRelayClose(WebsocketCallback $event): void {
		$this->logger->log("INFO", "Reconnecting to Tyr Websocket relay in 10s.");
		$this->mustReconnect = false;
		$this->timer->callLater(10, [$this->tyrClient, 'connect']);
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecOverview(array $specs, string $name, string $subCommand): array {
		$count = count($specs);
		if (!$count) {
			return ["No {$name}s available."];
		}
		$blobs = [];
		foreach ($specs as $spec) {
			$description = $spec->description ?? "Someone forgot to add a description";
			$entry = "<header2>{$spec->name}<end>\n".
				"<tab><i>".
				join("\n<tab>", explode("\n", trim($description))).
				"</i>";
			if (count($spec->params)) {
				$entry .= "\n<tab>[" . $this->text->makeChatcmd("details", "/tell <myname> relay list {$subCommand} {$spec->name}") . "]";
			}
			$blobs []= $entry;
		}
		$blob = join("\n\n", $blobs);
		return (array)$this->text->makeBlob("Available {$name}s ({$count})", $blob);
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecDetails(array $specs, string $key, string $name): array {
		$spec = $specs[$key] ?? null;
		if (!isset($spec)) {
			return ["No {$name} <highlight>{$key}<end> found."];
		}
		$description = $spec->description ?? "Someone forgot to add a description";
		$blob = "<header2>Description<end>\n".
			"<tab>" . join("\n<tab>", explode("\n", trim($description))).
			"\n\n".
			"<header2>Parameters<end>\n";
		foreach ($spec->params as $param) {
			$blob .= "<tab><highlight>{$param->type} {$param->name}<end>";
			if (!$param->required) {
				$blob .= " (optional)";
			}
			$blob .= "\n<tab><i>".
				join("</i>\n<tab><i>", explode("\n", $param->description ?? "No description")).
				"</i>\n\n";
		}
		return (array)$this->text->makeBlob("{$spec->name}", $blob);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list protocols?$/i")
	 */
	public function relayListProtocolsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->relayProtocols,
				"relay protocol",
				"protocol"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list protocol (.+)$/i")
	 */
	public function relayListProtocolDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->relayProtocols,
				$args[1],
				"relay protocol",
				"protocol"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list transports?$/i")
	 */
	public function relayListTransportsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->transports,
				"relay transport",
				"transport"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list transport (.+)$/i")
	 */
	public function relayListTransportDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->transports,
				$args[1],
				"relay transport",
				"transport"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list layers?$/i")
	 */
	public function relayListStacksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->stackElements,
				"relay layer",
				"layer"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list layer (.+)$/i")
	 */
	public function relayListStackDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->stackElements,
				$args[1],
				"relay layer"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay add (?<name>.+?) (?<spec>.+)$/is")
	 */
	public function relayAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (strlen($args['name']) > 100) {
			$sendto->reply("The name of the relay must be 100 characters max.");
			return;
		}
		$relayConf = new RelayConfig();
		$relayConf->name = $args['name'];
		$parser = new RelayLayerExpressionParser();
		try {
			$layers = $parser->parse($args["spec"]);
		} catch (LayerParserException $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$this->db->beginTransaction();
		try {
			$relayConf->id = $this->db->insert(static::DB_TABLE, $relayConf);
			foreach ($layers as $layer) {
				$layer->relay_id = $relayConf->id;
				$layer->id = $this->db->insert(static::DB_TABLE_LAYER, $layer);
				foreach ($layer->arguments as $argument) {
					$argument->layer_id = $layer->id;
					$argument->id = $this->db->insert(static::DB_TABLE_ARGUMENT, $argument);
				}
				$relayConf->layers []= $layer;
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error saving the relay: " . $e->getMessage());
			return;
		}
		$layers = [];
		foreach ($relayConf->layers as $layer) {
			$layers []= $layer->toString();
		}
		try {
			$relay = $this->createRelayFromDB($relayConf);
		} catch (Exception $e) {
			$this->db->rollback();
			$sendto->reply($e->getMessage());
			return;
		}
		if (!$this->addRelay($relay)) {
			$this->db->rollback();
			$sendto->reply("A relay with that name is already registered");
			return;
		}
		$this->db->commit();
		$sendto->reply(
			"Relay <highlight>{$args['name']}<end> added. ".
			"Make sure to set a <highlight><symbol>route<end> ".
			"to specify which messages to relay from where to where."
		);
		$relay->init(function() use ($relay) {
			$this->logger->log('INFO', "Relay " . $relay->getName() . " initialized");
		});
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay$/i")
	 * @Matches("/^relay list$/i")
	 */
	public function relayListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$relays = $this->getRelays();
		if (empty($relays)) {
			$sendto->reply("There are no relays defined.");
			return;
		}
		$blobs = [];
		foreach ($relays as $relay) {
			$blob = "<header2>{$relay->name}<end>\n".
				"<tab>Transport: <highlight>" . $relay->layers[0]->toString() . "<end>\n";
			for ($i = 1; $i < count($relay->layers)-1; $i++) {
				$blob .= "<tab>Layer: <highlight>" . $relay->layers[$i]->toString() . "<end>\n";
			}
			$blob .= "<tab>Protocol: <highlight>" . $relay->layers[count($relay->layers)-1]->toString() . "<end>\n";
			$live = $this->relays[$relay->name] ?? null;
			if (isset($live)) {
				$blob .= "<tab>Status: " . $live->getStatus();
			} else {
				$blob .= "<tab>Status: <red>error<end>";
			}
			$delLink = $this->text->makeChatcmd(
				"delete",
				"/tell <myname> relay rem {$relay->id}"
			);
			$blobs []= $blob . " [{$delLink}]";
		}
		$msg = $this->text->makeBlob(
			"Relays (" . count($relays) . ")",
			join("\n\n", $blobs)
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay (?:rem|del) (?<id>\d+)$/i")
	 * @Matches("/^relay (?:rem|del) (?<name>.+)$/i")
	 */
	public function relayRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$relay = isset($args['id'])
			? $this->getRelay((int)$args['id'])
			: $this->getRelayByName($args['name']);
		if (!isset($relay)) {
			$sendto->reply(
				"Relay <highlight>".
				(isset($args['id']) ? "#{$args['id']}" : $args['name']).
				"<end> not found."
			);
			return;
		}
		/** @var int[] List of modifier-ids for the route */
		$layers = array_column($relay->layers, "id");
		$this->db->beginTransaction();
		try {
			if (count($layers)) {
				$this->db->table(static::DB_TABLE_ARGUMENT)
					->whereIn("layer_id", $layers)
					->delete();
				$this->db->table(static::DB_TABLE_LAYER)
					->where("relay_id", $relay->id)
					->delete();
			}
			$this->db->table(static::DB_TABLE)
				->delete($relay->id);
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error deleting the relay: " . $e->getMessage());
			return;
		}
		$this->db->commit();
		$liveRelay = $this->relays[$relay->name] ?? null;
		unset($this->relays[$relay->name]);
		if (isset($liveRelay)) {
			$liveRelay->deinit(function(Relay $relay) {
				$this->logger->log('INFO', "Relay " . $relay->getName() . " destroyed");
				unset($relay);
			});
		}
		$sendto->reply(
			"Relay #{$relay->id} (<highlight>{$relay->name}<end>) deleted."
		);
	}

	/**
	 * Read all defined relays from the database
	 * @return RelayConfig[]
	 */
	public function getRelays(): array {
		$arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->groupBy("layer_id");
		$layers = $this->db->table(static::DB_TABLE_LAYER)
			->orderBy("id")
			->asObj(RelayLayer::class)
			->each(function (RelayLayer $layer) use ($arguments): void {
				$layer->arguments = $arguments->get($layer->id, new Collection())->toArray();
			})
			->groupBy("relay_id");
		$relays = $this->db->table(static::DB_TABLE)
			->orderBy("id")
			->asObj(RelayConfig::class)
			->each(function(RelayConfig $relay) use ($layers): void {
				$relay->layers = $layers->get($relay->id, new Collection())->toArray();
			})
			->toArray();
		return $relays;
	}

	/** Read a relay by its ID */
	public function getRelay(int $id): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("id", $id)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Read a relay by its name */
	public function getRelayByName(string $name): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("name", $name)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Add layers and args to a relay from the DB */
	protected function completeRelay(RelayConfig $relay): void {
		$relay->layers = $this->db->table(static::DB_TABLE_LAYER)
		->where("relay_id", $relay->id)
		->orderBy("id")
		->asObj(RelayLayer::class)
		->toArray();
		foreach ($relay->layers as $layer) {
			$layer->arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->where("layer_id", $layer->id)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->toArray();
		}
	}

	public function addRelay(Relay $relay): bool {
		if (isset($this->relays[$relay->getName()])) {
			return false;
		}
		$this->relays[$relay->getName()] = $relay;
		return true;
	}

	protected function createRelayFromDB(RelayConfig $conf): Relay {
		$relay = new Relay($conf->name);
		Registry::injectDependencies($relay);
		if (count($conf->layers) < 2) {
			throw new Exception(
				"Every relay must have at least 1 transport and 1 protocol."
			);
		}
		// The order is assumed to be transport --- protocol
		// If it's the other way around, let's reverse it
		if (
			!isset($this->transports[$conf->layers[0]->layer])
			&& isset($this->relayProtocols[$conf->layers[0]->layer])
		) {
			$conf->layers = array_reverse($conf->layers);
		}

		$stack = [];
		$transport = array_shift($conf->layers);
		$spec = $this->transports[strtolower($transport->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$transport->layer}<end> is not a ".
				"known transport for relaying. Perhaps the order was wrong?"
			);
		}
		$transportLayer = $this->getRelayLayer(
			$transport->layer,
			$transport->getKVArguments(),
			$spec
		);

		for ($i = 0; $i < count($conf->layers)-1; $i++) {
			$layerName = strtolower($conf->layers[$i]->layer);
			$spec = $this->stackElements[$layerName] ?? null;
			if (!isset($spec)) {
				throw new Exception(
					"<highlight>{$layerName}<end> is not a ".
					"known layer for relaying. Perhaps the order was wrong?"
				);
			}
			$stack []= $this->getRelayLayer(
				$layerName,
				$conf->layers[$i]->getKVArguments(),
				$spec
			);
		}

		$proto = array_pop($conf->layers);
		$spec = $this->relayProtocols[strtolower($proto->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$proto->layer}<end> is not a ".
				"known relay protocol. Perhaps the order was wrong?"
			);
		}
		$protocolLayer = $this->getRelayLayer(
			$proto->layer,
			$proto->getKVArguments(),
			$spec
		);
		$relay->setStack($transportLayer, $protocolLayer, ...$stack);
		return $relay;
	}

	/**
	 * Get a fully configured relay layer or null if not possible
	 * @param string $name Name of the layer
	 * @param array<string,string> $params The parameters of the layer
	 */
	public function getRelayLayer(string $name, array $params, ClassSpec $spec): object {
		$name = strtolower($name);
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
			$result = new $class(...$arguments);
			Registry::injectDependencies($result);
			return $result;
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} modifier: " . $e->getMessage());
		}
	}
}
