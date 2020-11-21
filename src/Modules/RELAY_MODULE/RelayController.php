<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	AMQP,
	AMQPExchange,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Modules\GUILD_MODULE\GuildController;
use PhpAmqpLib\Exchange\AMQPExchangeType;

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
 */
class RelayController {

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
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'relaytype',
			"Type of relay",
			"edit",
			"options",
			"1",
			"tell;private channel;amqp",
			'1;2;3'
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
			"Bot or AMQP exchange for Guildrelay",
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
		if ($this->settingManager->getInt('relaytype') === 3 && $relayBot !== 'Off') {
			foreach (explode(",", $relayBot) as $exchange) {
				$exchObject = new AMQPExchange();
				$exchObject->name = $exchange;
				$exchObject->type = AMQPExchangeType::FANOUT;
				$this->amqp->connectExchange($exchObject);
			}
		}
		$this->settingManager->registerChangeListener(
			'relaybot',
			[$this, 'relayBotChanges']
		);
		$this->settingManager->registerChangeListener(
			'relaytype',
			[$this, 'relayTypeChanges']
		);
	}

	/**
	 * When the relaytype changes from/to AMQP relay, (un)subscribe to the exchanges
	 */
	public function relayTypeChanges(string $setting, string $oldValue, string $newValue, $extraData): void {
		$relayBot = $this->settingManager->getString('relaybot');
		if (($oldValue !== "3" && $newValue !== "3") || $relayBot === 'Off') {
			return;
		}

		$exchanges = array_values(array_diff(explode(",", $relayBot), ["Off"]));
		if ($oldValue === "3") {
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
		if (!in_array(strtolower($sender), explode(",", strtolower($this->settingManager->getString('relaybot'))))
			|| !preg_match("/^grc (.+)$/s", $message, $arr)) {
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
	public function orgChatToRelayEvent(Event $eventObj): void {
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * @Event("priv")
	 * @Description("Sends private channel chat to relay")
	 */
	public function privChatToRelayEvent(Event $eventObj): void {
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
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
			if (count($altInfo->alts) > 0) {
				$msg = $this->playerManager->getInfo($whois) . " has joined the private channel. " . $altInfo->getAltsBlob(true);
			} else {
				$msg = $this->playerManager->getInfo($whois) . " has joined the private channel.";
			}
		} else {
			if (count($altInfo->alts) > 0) {
				$msg = "$sender has joined the private channel. " . $altInfo->getAltsBlob(true);
			} else {
				$msg = "$sender has joined the private channel.";
			}
		}

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
}
