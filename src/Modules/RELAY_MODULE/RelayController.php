<?php

namespace Budabot\Modules\RELAY_MODULE;

use Budabot\Core\Event;

/**
 * @author Tyrence
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
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Budabot\Core\Modules\ALTS\AltsController $altsController
	 * @Inject
	 */
	public $altsController;
	
	/**
	 * @var \Budabot\Core\Modules\PREFERENCES\Preferences $preferences
	 * @Inject
	 */
	public $preferences;
	
	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
	 * @Inject
	 */
	public $playerManager;
	
	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;

	/**
	 * @var \Budabot\Core\AMQP $amqp
	 * @Inject
	 */
	public $amqp;
	
	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/** @Setup */
	public function setup() {
		$this->settingManager->add($this->moduleName, 'relaytype', "Type of relay", "edit", "options", "1", "tell;private channel;amqp", '1;2;3');
		$this->settingManager->add($this->moduleName, 'relaysymbol', "Symbol for external relay", "edit", "options", "@", "!;#;*;@;$;+;-");
		$this->settingManager->add($this->moduleName, 'relay_symbol_method', "When to relay messages", "edit", "options", "0", "Always relay;Relay when symbol;Relay unless symbol", '0;1;2');
		$this->settingManager->add($this->moduleName, 'relaybot', "Bot or AMQP exchange for Guildrelay", "edit", "text", "Off", "Off", '', "mod", "relaybot.txt");
		$this->settingManager->add($this->moduleName, 'bot_relay_commands', "Relay commands and results over the bot relay", "edit", "options", "1", "true;false", "1;0");
		$this->settingManager->add($this->moduleName, 'relay_color_guild', "Color of messages from relay to guild channel", 'edit', "color", "<font color='#C3C3C3'>");
		$this->settingManager->add($this->moduleName, 'relay_color_priv', "Color of messages from relay to private channel", 'edit', "color", "<font color='#C3C3C3'>");
		$this->settingManager->add($this->moduleName, 'relay_guild_abbreviation', 'Abbreviation to use for org name', 'edit', 'text', 'none', 'none');
		$this->settingManager->add($this->moduleName, 'relay_ignore', 'Semicolon-separated list of people not to relay away', 'edit', 'text', '', 'none');
		$this->settingManager->add($this->moduleName, 'relay_filter_out', 'RegExp filtering outgoing messages', 'edit', 'text', '', 'none');
		$this->settingManager->add($this->moduleName, 'relay_filter_in', 'RegExp filtering messages to org chat', 'edit', 'text', '', 'none');
		$this->settingManager->add($this->moduleName, 'relay_filter_in_priv', 'RegExp filtering messages to priv chat', 'edit', 'text', '', 'none');
		
		$this->commandAlias->register($this->moduleName, "macro settings save relaytype 1|settings save relaysymbol Always relay|settings save relaybot", "tellrelay");
		$relayBot = $this->settingManager->get('relaybot');
		if ($this->settingManager->get('relaytype') == 3 && $relayBot !== 'off') {
			foreach (explode(",", $relayBot) as $exchange) {
				$this->amqp->connectExchange($exchange);
			}
		}
		$this->settingManager->registerChangeListener('relaybot', [$this, 'relayBotChanges']);
		$this->settingManager->registerChangeListener('relaytype', [$this, 'relayTypeChanges']);
	}

	/**
	 * When the relaytype changes from/to AMQP relay, (un)subscribe to the exchanges
	 *
	 * @param string $setting   "relaytype"
	 * @param string $oldValue  The old setting value
	 * @param string $newValue  The new setting value about to be set
	 * @param mixed  $extraData Extra parameters given when setting the listener
	 */
	public function relayTypeChanges($setting, $oldValue, $newValue, $extraData) {
		$relayBot = $this->settingManager->get('relaybot');
		if (($oldValue != 3 && $newValue != 3) || $relayBot === 'off') {
			return;
		}

		$exchanges = array_values(array_diff(explode(",", $relayBot), array("off")));
		if ($oldValue == 3) {
			foreach ($exchanges as $unsub) {
				$this->amqp->disconnectExchange($unsub);
			}
			return;
		}
		foreach ($exchanges as $sub) {
			$this->amqp->connectExchange($sub);
		}
	}

	/**
	 * When the relaybot changes for AMQP relays, (un)subscribe from the exchanges
	 *
	 * @param string $setting   "relaybot"
	 * @param string $oldValue  The old setting value
	 * @param string $newValue  The new setting value about to be set
	 * @param mixed  $extraData Extra parameters given when setting the listener
	 */
	public function relayBotChanges($setting, $oldValue, $newValue, $extraData) {
		if ($this->settingManager->get('relaytype') != 3) {
			return;
		}
		$oldExchanges = explode(",", $oldValue);
		$newExchanges = explode(",", $newValue);
		if ($newValue === 'off') {
			$newExchanges = [];
		}

		foreach (array_values(array_diff($oldExchanges, $newExchanges)) as $unsub) {
			$this->amqp->disconnectExchange($unsub);
		}
		foreach (array_values(array_diff($newExchanges, $oldExchanges)) as $sub) {
			$this->amqp->connectExchange($sub);
		}
	}
	
	/**
	 * @HandlesCommand("grc")
	 */
	public function grcCommand($message, $channel, $sender, $sendto, $args) {
		$this->processIncomingRelayMessage($sender, $message);
	}

	/**
	 * @Event("amqp")
	 * @Description("Receive relay messages from other bots via AMQP")
	 */
	public function receiveRelayMessageAMQP(Event $eventObj) {
		$this->processIncomingRelayMessage($eventObj->channel, $eventObj->message);
	}
	
	/**
	 * @Event("extPriv")
	 * @Description("Receive relay messages from other bots in the relay bot private channel")
	 */
	public function receiveRelayMessageExtPrivEvent(Event $eventObj) {
		$this->processIncomingRelayMessage($eventObj->channel, $eventObj->message);
	}
	
	/**
	 * @Event("priv")
	 * @Description("Receive relay messages from other bots in this bot's own private channel")
	 */
	public function receiveRelayMessagePrivEvent(Event $eventObj) {
		$this->processIncomingRelayMessage($eventObj->sender, $eventObj->message);
	}
	
	public function processIncomingRelayMessage($sender, $message) {
		if (!in_array(strtolower($sender), explode(",", strtolower($this->settingManager->get('relaybot'))))
			|| !preg_match("/^grc (.+)$/s", $message, $arr)) {
			return;
		}
		$msg = $arr[1];
		if (!$this->matchesFilter($this->settingManager->get('relay_filter_in'), $message)) {
			$this->chatBot->sendGuild($this->settingManager->get('relay_color_guild') . $msg, true);
		}

		if ($this->settingManager->get("guest_relay") == 1) {
			if (!$this->matchesFilter($this->settingManager->get('relay_filter_in_priv'), $message)) {
				$this->chatBot->sendPrivate($this->settingManager->get('relay_color_priv') . $msg, true);
			}
		}
	}
	
	/**
	 * @Event("guild")
	 * @Description("Sends org chat to relay")
	 */
	public function orgChatToRelayEvent(Event $eventObj) {
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
	}
	
	/**
	 * @Event("priv")
	 * @Description("Sends private channel chat to relay")
	 */
	public function privChatToRelayEvent(Event $eventObj) {
		$this->processOutgoingRelayMessage($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * Check if a message by a sender should not be relayed due to filters
	 *
	 * @param string $sender Name of the person sending the message
	 * @param string $message The message that wants to be relayed
	 * @return bool
	 */
	public function isFilteredMessage($sender, $message) {
		$toIgnore = array_diff(
			explode(";", strtolower($this->settingManager->get('relay_ignore'))),
			[""]
		);
		if (in_array(strtolower($sender), $toIgnore)) {
			return true;
		}
		return $this->matchesFilter($this->settingManager->get('relay_filter_out'), $message);
	}

	/**
	 * Checks if a message matches a filter
	 *
	 * @param string $filter  The filter
	 * @param string $message The message to check
	 * @return bool
	 */
	public function matchesFilter($filter, $message) {
		if (!strlen($filter)) {
			return false;
		}
		$escapedFilter = str_replace("/", "\\/", $filter);
		return (bool)@preg_match("/$escapedFilter/", $message);
	}
	
	public function processOutgoingRelayMessage($sender, $message, $type) {
		if ($this->settingManager->get("relaybot") == "Off") {
			return;
		}
		// Don't relay commands if bot_relay_commands is turned off
		if ($this->settingManager->get("bot_relay_commands") == 0
			&& $message[0] == $this->settingManager->get("symbol")) {
			return;
		}
		if ($this->isFilteredMessage($sender, $message)) {
			return;
		}
		$relayMessage = '';
		if ($this->settingManager->get('relay_symbol_method') == '0') {
			$relayMessage = $message;
		} elseif ($this->settingManager->get('relay_symbol_method') == '1' && $message[0] == $this->settingManager->get('relaysymbol')) {
			$relayMessage = substr($message, 1);
		} elseif ($this->settingManager->get('relay_symbol_method') == '2' && $message[0] != $this->settingManager->get('relaysymbol')) {
			$relayMessage = $message;
		} else {
			return;
		}

		if (!$this->util->isValidSender($sender)) {
			$sender_link = '';
		} else {
			$sender_link = ' ' . $this->text->makeUserlink($sender) . ':';
		}

		if ($type == "guild") {
			$msg = "grc [<myguild>]{$sender_link} {$relayMessage}";
		} elseif ($type == "priv") {
			if (strlen($this->chatBot->vars["my_guild"])) {
				$msg = "grc [<myguild>] [Guest]{$sender_link} {$relayMessage}";
			} else {
				$msg = "grc [<myname>]{$sender_link} {$relayMessage}";
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
	public function acceptPrivJoinEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get("relaytype") == 2 && strtolower($sender) == strtolower($this->settingManager->get("relaybot"))) {
			$this->chatBot->privategroup_join($sender);
		}
	}
	
	/**
	 * @Event("orgmsg")
	 * @Description("Relay Org Messages")
	 */
	public function relayOrgMessagesEvent(Event $eventObj) {
		if ($this->settingManager->get("relaybot") != "Off") {
			$msg = "grc [<myguild>] {$eventObj->message}<end>";
			$this->sendMessageToRelay($msg);
		}
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Sends Logon messages over the relay")
	 */
	public function relayLogonMessagesEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get("relaybot") != "Off" && isset($this->chatBot->guildmembers[$sender]) && $this->chatBot->isReady()) {
			$whois = $this->playerManager->getByName($sender);

			$msg = '';
			if ($whois === null) {
				$msg = "$sender logged on.";
			} else {
				$msg = $this->playerManager->getInfo($whois);

				$msg .= " logged on.";

				$altInfo = $this->altsController->getAltInfo($sender);
				if (count($altInfo->alts) > 0) {
					$msg .= " " . $altInfo->getAltsBlob(false, true);
				}

				$logon_msg = $this->preferences->get($sender, 'logon_msg');
				if ($logon_msg !== false && $logon_msg != '') {
					$msg .= " - " . $logon_msg;
				}
			}

			if (strlen($this->chatBot->vars["my_guild"])) {
				$this->sendMessageToRelay("grc [<myguild>] ".$msg);
			} else {
				$this->sendMessageToRelay("grc [<myname>] ".$msg);
			}
		}
	}
	
	/**
	 * @Event("logOff")
	 * @Description("Sends Logoff messages over the relay")
	 */
	public function relayLogoffMessagesEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get("relaybot") != "Off" && isset($this->chatBot->guildmembers[$sender]) && $this->chatBot->isReady()) {
			if (strlen($this->chatBot->vars["my_guild"])) {
				$this->sendMessageToRelay("grc [<myguild>] <highlight>{$sender}<end> logged off");
			} else {
				$this->sendMessageToRelay("grc [<myname>] <highlight>{$sender}<end> logged off");
			}
		}
	}
	
	/**
	 * @Event("joinPriv")
	 * @Description("Sends a message to the relay when someone joins the private channel")
	 */
	public function relayJoinPrivMessagesEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get('relaybot') != 'Off') {
			$whois = $this->playerManager->getByName($sender);
			$altInfo = $this->altsController->getAltInfo($sender);

			if ($whois !== null) {
				if (count($altInfo->alts) > 0) {
					$msg = $this->playerManager->getInfo($whois) . " has joined the private channel. " . $altInfo->getAltsBlob(false, true);
				} else {
					$msg = $this->playerManager->getInfo($whois) . " has joined the private channel.";
				}
			} else {
				if (count($altInfo->alts) > 0) {
					$msg = "$sender has joined the private channel. " . $altInfo->getAltsBlob(false, true);
				} else {
					$msg = "$sender has joined the private channel.";
				}
			}

			if (strlen($this->chatBot->vars["my_guild"])) {
				$this->sendMessageToRelay("grc [<myguild>] " . $msg);
			} else {
				$this->sendMessageToRelay("grc [<myname>] " . $msg);
			}
		}
	}
	
	/**
	 * @Event("leavePriv")
	 * @Description("Sends a message to the relay when someone leaves the private channel")
	 */
	public function relayLeavePrivMessagesEvent(Event $eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get('relaybot') != 'Off') {
			$msg = "<highlight>{$sender}<end> has left the private channel.";
			if (strlen($this->chatBot->vars["my_guild"])) {
				$this->sendMessageToRelay("grc [<myguild>] " . $msg);
			} else {
				$this->sendMessageToRelay("grc [<myname>] " . $msg);
			}
		}
	}
	
	public function sendMessageToRelay($message) {
		$relayBot = $this->settingManager->get('relaybot');
		$message = str_ireplace("<myguild>", $this->getGuildAbbreviation(), $message);

		// since we are using the aochat methods, we have to call formatMessage manually to handle colors and bot name replacement
		$message = $this->text->formatMessage($message);

		// we use the aochat methods so the bot doesn't prepend default colors
		if ($this->settingManager->get('relaytype') == 2) {
			$this->chatBot->send_privgroup($relayBot, $message);
		} elseif ($this->settingManager->get('relaytype') == 3) {
			foreach (explode(",", $relayBot) as $exchange) {
				$this->amqp->sendMessage($exchange, $message);
			}
		} elseif ($this->settingManager->get('relaytype') == 1) {
			foreach (explode(",", $relayBot) as $recipient) {
				$this->chatBot->send_tell($recipient, $message);

				// manual logging is only needed for tell relay
				$this->logger->logChat("Out. Msg.", $recipient, $message);
			}
		}
	}

	public function getGuildAbbreviation() {
		if ($this->settingManager->get('relay_guild_abbreviation') != 'none') {
			return $this->settingManager->get('relay_guild_abbreviation');
		} else {
			return $this->chatBot->vars["my_guild"];
		}
	}
}
