<?php

namespace Budabot\Core;

use Addendum\ReflectionAnnotatedClass;
use Budabot\Core\Annotations\DefineCommand;
use Budabot\Core\Event;

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

/**
 * @Instance("chatBot")
 */
class Budabot extends AOChat {

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\CommandManager $commandManager
	 * @Inject
	 */
	public $commandManager;

	/**
	 * @var \Budabot\Core\SubcommandManager $subcommandManager
	 * @Inject
	 */
	public $subcommandManager;

	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;

	/**
	 * @var \Budabot\Core\EventManager $eventManager
	 * @Inject
	 */
	public $eventManager;

	/**
	 * @var \Budabot\Core\HelpManager $helpManager
	 * @Inject
	 */
	public $helpManager;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Modules\BAN\BanController $banController
	 * @Inject
	 */
	public $banController;

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
	 * @var \Budabot\Core\Modules\LIMITS\LimitsController $limitsController
	 * @Inject
	 */
	public $limitsController;

	/**
	 * @var \Budabot\Core\BuddylistManager $buddylistManager
	 * @Inject
	 */
	public $buddylistManager;

	/**
	 * @var \Budabot\Modules\RELAY_MODULE\RelayController $relayController
	 * @Inject
	 */
	public $relayController;

	/**
	 * @var \Budabot\Core\SettingObject $setting
	 * @Inject
	 */
	public $setting;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger("Core")
	 */
	public $logger;

	/** @var bool $ready */
	public $ready = false;

	/** @var array[string]bool $chatlist */
	public $chatlist = array();

	/**
	 * The rank for each member of this bot's guild/org
	 * [(string)name => (int)rank]
	 * @var array[string]int $guildmembers
	 */
	public $guildmembers = array();

	/**
	 * The configuration variables of this bot as given in the config file
	 * [(string)var => (mixed)value]
	 *
	 * @var array[string]mixed $vars
	 */
	public $vars;

	/**
	 * How many buddies can this bot hld
	 *
	 * @var integer $buddyListSize
	 */
	private $buddyListSize = 0;

	/**
	 * A list of channels that we ignore messages from
	 *
	 * Ignore Messages from Vicinity/IRRK New Wire/OT OOC/OT Newbie OOC...
	 *
	 * @var string[] $channelsToIgnore
	 */
	public $channelsToIgnore = array(
		'IRRK News Wire', 'OT OOC', 'OT Newbie OOC', 'OT shopping 11-50',
		'Tour Announcements', 'Neu. Newbie OOC', 'Neu. shopping 11-50', 'Neu. OOC', 'Clan OOC',
		'Clan Newbie OOC', 'Clan shopping 11-50', 'OT German OOC', 'Clan German OOC', 'Neu. German OOC'
	);

	/**
	 * Initialize the bot
	 *
	 * @param array[string]mixed $vars The configuration variables of the bot
	 * @return void
	 */
	public function init(&$vars) {
		$this->vars = $vars;

		// Set startup time
		$this->vars["startup"] = time();

		$this->logger->log('DEBUG', 'Initializing bot');

		// Create core tables if they don't already exist
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS cmdcfg_<myname> (".
			"`module` VARCHAR(50), ".
			"`cmdevent` VARCHAR(6), ".
			"`type` VARCHAR(18), ".
			"`file` TEXT, ".
			"`cmd` VARCHAR(50), ".
			"`admin` VARCHAR(10), ".
			"`description` VARCHAR(75) DEFAULT 'none', ".
			"`verify` INT DEFAULT '0', ".
			"`status` INT DEFAULT '0', ".
			"`dependson` VARCHAR(25) DEFAULT 'none', ".
			"`help` VARCHAR(255)".
			")"
		);
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS eventcfg_<myname> (".
			"`module` VARCHAR(50), ".
			"`type` VARCHAR(18), ".
			"`file` VARCHAR(255), ".
			"`description` VARCHAR(75) DEFAULT 'none', ".
			"`verify` INT DEFAULT '0', ".
			"`status` INT DEFAULT '0', ".
			"`help` VARCHAR(255)".
			")"
		);
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS settings_<myname> (".
			"`name` VARCHAR(50) NOT NULL, ".
			"`module` VARCHAR(50), ".
			"`type` VARCHAR(30), ".
			"`mode` VARCHAR(10), ".
			"`value` VARCHAR(255) DEFAULT '0', ".
			"`options` VARCHAR(255) DEFAULT '0', ".
			"`intoptions` VARCHAR(50) DEFAULT '0', ".
			"`description` VARCHAR(75), ".
			"`source` VARCHAR(5), ".
			"`admin` VARCHAR(25), ".
			"`verify` INT DEFAULT '0', ".
			"`help` VARCHAR(255)".
			")"
		);
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS hlpcfg_<myname> (".
			"`name` VARCHAR(25) NOT NULL, ".
			"`module` VARCHAR(50), ".
			"`file` VARCHAR(255), ".
			"`description` VARCHAR(75), ".
			"`admin` VARCHAR(10), ".
			"`verify` INT DEFAULT '0'".
			")"
		);
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS cmd_alias_<myname> (".
			"`cmd` VARCHAR(255) NOT NULL, ".
			"`module` VARCHAR(50), ".
			"`alias` VARCHAR(25) NOT NULL, ".
			"`status` INT DEFAULT '0'".
			")"
		);

		// Prepare command/event settings table
		$this->db->exec("UPDATE cmdcfg_<myname> SET `verify` = 0");
		$this->db->exec("UPDATE eventcfg_<myname> SET `verify` = 0");
		$this->db->exec("UPDATE settings_<myname> SET `verify` = 0");
		$this->db->exec("UPDATE hlpcfg_<myname> SET `verify` = 0");
		$this->db->exec("UPDATE eventcfg_<myname> SET `status` = 1 WHERE `type` = 'setup'");

		// To reduce queries load core items into memory
		$data = $this->db->query("SELECT * FROM cmdcfg_<myname> WHERE `cmdevent` = 'subcmd'");
		foreach ($data as $row) {
			$this->existing_subcmds[$row->type][$row->cmd] = true;
		}

		$data = $this->db->query("SELECT * FROM eventcfg_<myname>");
		foreach ($data as $row) {
			$this->existing_events[$row->type][$row->file] = true;
		}

		$data = $this->db->query("SELECT * FROM hlpcfg_<myname>");
		foreach ($data as $row) {
			$this->existing_helps[$row->name] = true;
		}

		$data = $this->db->query("SELECT * FROM settings_<myname>");
		foreach ($data as $row) {
			$this->existing_settings[$row->name] = true;
		}

		$this->db->beginTransaction();
		foreach (Registry::getAllInstances() as $name => $instance) {
			if (isset($instance->moduleName)) {
				$this->registerInstance($name, $instance);
			} else {
				$this->callSetupMethod($name, $instance);
			}
		}
		$this->db->commit();

		//remove arrays
		unset($this->existing_events);
		unset($this->existing_subcmds);
		unset($this->existing_settings);
		unset($this->existing_helps);

		//Delete old entrys in the DB
		$this->db->exec("DELETE FROM cmdcfg_<myname> WHERE `verify` = 0");
		$this->db->exec("DELETE FROM eventcfg_<myname> WHERE `verify` = 0");
		$this->db->exec("DELETE FROM settings_<myname> WHERE `verify` = 0");
		$this->db->exec("DELETE FROM hlpcfg_<myname> WHERE `verify` = 0");

		$this->commandManager->loadCommands();
		$this->subcommandManager->loadSubcommands();
		$this->commandAlias->load();
		$this->eventManager->loadEvents();
	}

	/**
	 * Connect to AO chat servers
	 *
	 * @param string $login The username to connect with
	 * @param string $password The password to connect with
	 * @param string $server Hostname of the login server
	 * @param string|int $port The port of the login server to connect to
	 * @return void
	 */
	public function connectAO($login, $password, $server, $port) {
		// Begin the login process
		$this->logger->log('INFO', "Connecting to AO Server...({$server}:{$port})");
		if (false === $this->connect($server, $port)) {
			$this->logger->log('ERROR', "Connection failed! Please check your Internet connection and firewall.");
			sleep(10);
			die();
		}

		$this->logger->log('INFO', "Authenticate login data...");
		if (false === $this->authenticate($login, $password)) {
			$this->logger->log('ERROR', "Authentication failed! Invalid username or password.");
			sleep(10);
			die();
		}

		$this->logger->log('INFO', "Logging in {$this->vars["name"]}...");
		if (false === $this->login($this->vars["name"])) {
			$this->logger->log('ERROR', "Character selection failed! Could not login on as character '{$this->vars["name"]}'.");
			sleep(10);
			die();
		}

		$this->buddyListSize += 1000;
		$this->logger->log('INFO', "All Systems ready!");
	}

	/**
	 * The main endless-loop of the bot
	 *
	 * @return void
	 */
	public function run() {
		$loop = new EventLoop();
		Registry::injectDependencies($loop);

		$continue = true;
		$signalHandler = function ($sigNo) use (&$continue) {
			$this->logger->log('INFO', 'Shutdown requested.');
			$continue = false;
		};
		pcntl_signal(SIGINT, $signalHandler);
		pcntl_signal(SIGTERM, $signalHandler);
		$callDispatcher = true;
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			$callDispatcher = false;
		}

		while ($continue) {
			$loop->execSingleLoop();
			$callDispatcher && pcntl_signal_dispatch();
		}
		$this->logger->log('INFO', 'Graceful shutdown.');
	}

	/**
	 * Process all packets in an endless loop
	 *
	 * @return void
	 */
	public function processAllPackets() {
		while ($this->processNextPacket()) {
		}
	}

	/**
	 * Wait for the next packet and process it
	 *
	 * @return bool true if a package was processed, otherwise false
	 */
	public function processNextPacket() {
		// when bot isn't ready we wait for packets
		// to make sure the server has finished sending them
		// before marking the bot as ready
		$packet = $this->waitForPacket($this->isReady() ? 0 : 1);
		if ($packet) {
			$this->process_packet($packet);
			return true;
		} else {
			$this->ready = true;
			return false;
		}
	}

	/**
	 * Send a message to a private channel
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param boolean $disable_relay Set to true to disable relaying the message into the org/guild channel
	 * @param string $group Name of the private group to send message into or null for the bot's own
	 * @return void
	 */
	public function sendPrivate($message, $disable_relay=false, $group=null) {
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendPrivate($page, $disable_relay, $group);
			}
			return;
		}

		if ($group == null) {
			$group = $this->setting->default_private_channel;
		}

		$message = $this->text->formatMessage($message);
		$senderLink = $this->text->makeUserlink($this->vars['name']);
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		$guestColorChannel = $this->settingManager->get('guest_color_channel');
		$privColor = $this->settingManager->get('default_priv_color');

		$this->send_privgroup($group, $privColor.$message);
		if ($this->isDefaultPrivateChannel($group)) {
			// relay to guild channel
			if (!$disable_relay && $this->settingManager->get('guild_channel_status') == 1 && $this->settingManager->get("guest_relay") == 1 && $this->settingManager->get("guest_relay_commands") == 1) {
				$this->send_guild("</font>{$guestColorChannel}[Guest]</font> {$senderLink}: {$privColor}$message</font>", "\0");
			}

			// relay to bot relay
			if (!$disable_relay && $this->settingManager->get("relaybot") != "Off" && $this->settingManager->get("bot_relay_commands") == 1) {
				if (strlen($this->chatBot->vars["my_guild"])) {
					$this->relayController->sendMessageToRelay("grc [{$guildNameForRelay}] [Guest] {$senderLink}: $message");
				} else {
					$this->relayController->sendMessageToRelay("grc [<myname>] {$senderLink}: $message");
				}
			}
		}
	}

	/**
	 * Send one or more messages into the org/guild channel
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param boolean $disable_relay Set to true to disable relaying the message into the bot's private channel
	 * @param int $priority The priority of the message or medium if unset
	 * @return void
	 */
	public function sendGuild($message, $disable_relay=false, $priority=null) {
		if ($this->settingManager->get('guild_channel_status') != 1) {
			return;
		}

		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendGuild($page, $disable_relay, $priority);
			}
			return;
		}

		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}

		$message = $this->text->formatMessage($message);
		$senderLink = $this->text->makeUserlink($this->vars['name']);
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		$guestColorChannel = $this->settingManager->get('guest_color_channel');
		$guildColor = $this->settingManager->get("default_guild_color");

		$this->send_guild($guildColor.$message, "\0", $priority);

		// relay to private channel
		if (!$disable_relay && $this->settingManager->get("guest_relay") == 1 && $this->settingManager->get("guest_relay_commands") == 1) {
			$this->send_privgroup($this->setting->default_private_channel, "</font>{$guestColorChannel}[{$guildNameForRelay}]</font> {$senderLink}: {$guildColor}$message</font>");
		}

		// relay to bot relay
		if (!$disable_relay && $this->settingManager->get("relaybot") != "Off" && $this->settingManager->get("bot_relay_commands") == 1) {
			$this->relayController->sendMessageToRelay("grc [{$guildNameForRelay}] {$senderLink}: $message");
		}
	}

	/**
	 * Send one or more messages to another player/bot
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param string $character Name of the person to send the tell to
	 * @param int $priority The priority of the message or medium if unset
	 * @param boolean $formatMessage If set, replace tags with their corresponding colors
	 * @return void
	 */
	public function sendTell($message, $character, $priority=null, $formatMessage=true) {
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendTell($page, $character, $priority);
			}
			return;
		}

		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}

		if ($formatMessage) {
			$message = $this->text->formatMessage($message);
			$tellColor = $this->settingManager->get("default_tell_color");
		}

		$this->logger->logChat("Out. Msg.", $character, $message);
		$this->send_tell($character, $tellColor.$message, "\0", $priority);
	}

	/**
	 * Send one or more messages into a public channel
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param string $channel Name of the channel to send the message to
	 * @param int $priority The priority of the message or medium if unset
	 * @return void
	 */
	public function sendPublic($message, $channel, $priority=null) {
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendPublic($page, $channel, $priority);
			}
			return;
		}

		if ($priority == null) {
			$priority = AOC_PRIORITY_MED;
		}

		$message = $this->text->formatMessage($message);
		$guildColor = $this->settingManager->get("default_guild_color");

		$this->send_group($channel, $guildColor.$message, "\0", $priority);
	}

	/**
	 * Returns a command type in the proper format
	 *
	 * @param string $type A space-separate list of any combination of "msg", "priv" and "guild"
	 * @param string $admin A space-separate list of access rights needed
	 * @return bool Success
	 */
	public function processCommandArgs(&$type, &$admin) {
		if ($type == "") {
			$type = array("msg", "priv", "guild");
		} else {
			$type = explode(' ', $type);
		}

		$admin = explode(' ', $admin);
		if (count($admin) == 1) {
			$admin = array_fill(0, count($type), $admin[0]);
		} elseif (count($admin) != count($type)) {
			$this->logger->log('ERROR', "The number of type arguments does not equal the number of admin arguments for command/subcommand registration");
			return false;
		}
		return true;
	}

	/**
	 * Proccess an incoming message packet that the bot receives
	 *
	 * @param \Budabot\Core\AOChatPacket $packet The packet to process
	 * @return void
	 */
	public function process_packet($packet) {
		try {
			$this->process_all_packets($packet);

			// event handlers
			switch ($packet->type) {
				case AOCP_LOGIN_OK: // 5
					$this->buddyListSize += 1000;
					break;
				case AOCP_GROUP_ANNOUNCE: // 60
					$this->processGroupAnnounce($packet->args);
					break;
				case AOCP_PRIVGRP_CLIJOIN: // 55, Incoming player joined private chat
					$this->processPrivateChannelJoin($packet->args);
					break;
				case AOCP_PRIVGRP_CLIPART: // 56, Incoming player left private chat
					$this->processPrivateChannelLeave($packet->args);
					break;
				case AOCP_BUDDY_ADD: // 40, Incoming buddy logon or off
					$this->processBuddyUpdate($packet->args);
					break;
				case AOCP_BUDDY_REMOVE: // 41, Incoming buddy removed
					$this->processBuddyRemoved($packet->args);
					break;
				case AOCP_MSG_PRIVATE: // 30, Incoming Msg
					$this->processPrivateMessage($packet->args);
					break;
				case AOCP_PRIVGRP_MESSAGE: // 57, Incoming priv message
					$this->processPrivateChannelMessage($packet->args);
					break;
				case AOCP_GROUP_MESSAGE: // 65, Public and guild channels
					$this->processPublicChannelMessage($packet->args);
					break;
				case AOCP_PRIVGRP_INVITE: // 50, private channel invite
					$this->processPrivateChannelInvite($packet->args);
					break;
			}
		} catch (StopExecutionException $e) {
			$this->logger->log('DEBUG', 'Execution stopped prematurely', $e);
		}
	}

	/**
	 * Fire associated events for a received packet
	 *
	 * @param \Budabot\Core\AOChatPacket $packet The received packet
	 * @return void
	 */
	public function process_all_packets($packet) {
		// fire individual packets event
		$eventObj = new Event();
		$eventObj->type = "packet({$packet->type})";
		$eventObj->packet = $packet;
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Handle an incoming AOCP_GROUP_ANNOUNCE packet
	 *
	 * @param string[] $args [
	 * 	0 => Name of the org,
	 * 	1 => The message
	 * ]
	 * @return void
	 */
	public function processGroupAnnounce($args) {
		$orgId = $this->getOrgId($args[0]);
		$this->logger->log('DEBUG', "AOCP_GROUP_ANNOUNCE => name: '$args[1]'");
		if ($orgId) {
			$this->vars["my_guild_id"] = $orgId;
		}
	}

	/**
	 * Handle a player joining a private group
	 *
	 * @param int[] $args [
	 * 	0 => UserID of the channel
	 * 	1 => UserID who joined
	 * ]
	 * @return void
	 */
	public function processPrivateChannelJoin($args) {
		$eventObj = new Event();
		$channel = $this->lookup_user($args[0]);
		$charId = $args[1];
		$sender = $this->lookup_user($charId);
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_CLIJOIN => channel: '$channel' sender: '$sender'");

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "joinpriv";

			$this->logger->logChat("Priv Group", -1, "$sender joined the channel.");

			// Remove sender if they are banned
			if ($this->banController->isBanned($charId)) {
				$this->privategroup_kick($sender);
				return;
			}

			// Add sender to the chatlist
			$this->chatlist[$sender] = true;

			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle a player leaving a private group
	 *
	 * @param int[] $args [
	 * 	0 => UserID of the channel,
	 * 	1 => UserID who left
	 * ]
	 * @return void
	 */
	public function processPrivateChannelLeave($args) {
		$eventObj = new Event();
		$channel = $this->lookup_user($args[0]);
		$sender = $this->lookup_user($args[1]);
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_CLIPART => channel: '$channel' sender: '$sender'");

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "leavepriv";

			$this->logger->logChat("Priv Group", -1, "$sender left the channel.");

			// Remove from Chatlist array
			unset($this->chatlist[$sender]);

			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle logon/logoff events of friends
	 *
	 * @param int[] $args [
	 * 	0 => UserID logging on/off,
	 * 	1 => 0 == logoff, 1 == login
	 * ]
	 * @return void
	 */
	public function processBuddyUpdate($args) {
		$sender	= $this->lookup_user($args[0]);
		$status	= 0 + $args[1];

		$eventObj = new Event();
		$eventObj->sender = $sender;

		$this->logger->log('DEBUG', "AOCP_BUDDY_ADD => sender: '$sender' status: '$status'");

		$this->buddylistManager->update($args);

		// Ignore Logon/Logoff from other bots or phantom logon/offs
		if ($sender == "") {
			return;
		}

		// Status => 0: logoff  1: logon
		if ($status == 0) {
			$eventObj->type = "logoff";

			$this->logger->log('DEBUG', "$sender logged off");

			$this->eventManager->fireEvent($eventObj);
		} elseif ($status == 1) {
			$eventObj->type = "logon";

			$this->logger->log('DEBUG', "$sender logged on");

			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle that a friend was removed from the friendlist
	 *
	 * @param int[] $args [0 => UserID who was removed]
	 * @return void
	 */
	public function processBuddyRemoved($args) {
		$sender	= $this->lookup_user($args[0]);

		$this->logger->log('DEBUG', "AOCP_BUDDY_REMOVE => sender: '$sender'");

		$this->buddylistManager->updateRemoved($args);
	}

	/**
	 * Handle an incoming tell
	 *
	 * @param mixed[] $args [
	 * 	0 => UserID of the sender,
	 * 	1 => The message
	 * ]
	 * @return void
	 */
	public function processPrivateMessage($args) {
		$type = "msg";
		$charId = $args[0];
		$sender	= $this->lookup_user($charId);

		$this->logger->log('DEBUG', "AOCP_MSG_PRIVATE => sender: '$sender' message: '$args[1]'");

		// Removing tell color
		if (preg_match("/^<font color='#([0-9a-f]+)'>(.+)$/si", $args[1], $arr)) {
			$message = $arr[2];
		} else {
			$message = $args[1];
		}

		$eventObj = new Event();
		$eventObj->sender = $sender;
		$eventObj->type = $type;
		$eventObj->message = $message;

		$this->logger->logChat("Inc. Msg.", $sender, $message);

		// AFK/bot check
		if (preg_match("|$sender is AFK|si", $message)) {
			return;
		} elseif (preg_match("|I am away from my keyboard right now|si", $message)) {
			return;
		} elseif (preg_match("|Unknown command or access denied!|si", $message)) {
			return;
		} elseif (preg_match("|I am responding|si", $message)) {
			return;
		} elseif (preg_match("|I only listen|si", $message)) {
			return;
		} elseif (preg_match("|Error!|si", $message)) {
			return;
		} elseif (preg_match("|Unknown command input|si", $message)) {
			return;
		} elseif (preg_match("|/tell $sender !help|i", $message)) {
			return;
		}

		if ($this->banController->isBanned($charId)) {
			return;
		}

		$this->eventManager->fireEvent($eventObj);

		// remove the symbol if there is one
		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
		}

		// check tell limits
		if (!$this->limitsController->check($sender, $message)) {
			return;
		}

		$sendto = new PrivateMessageCommandReply($this, $sender);
		$this->commandManager->process($type, $message, $sender, $sendto);
	}

	/**
	 * Handle a message on a private channel
	 *
	 * @param mixed[] $args [
	 * 	0 => UserID of the channel,
	 * 	1 => UserID of the sender
	 * 	2 => The message
	 * ]
	 * @return void
	 */
	public function processPrivateChannelMessage($args) {
		$charId = $args[1];
		$sender	= $this->lookup_user($charId);
		$channel = $this->lookup_user($args[0]);
		$message = $args[2];

		$eventObj = new Event();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");
		$this->logger->logChat($channel, $sender, $message);

		if ($sender == $this->vars["name"] || $this->banController->isBanned($charId)) {
			return;
		}

		if ($this->isDefaultPrivateChannel($channel)) {
			$type = "priv";
			$eventObj->type = $type;

			$this->eventManager->fireEvent($eventObj);

			if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
				$message = substr($message, 1);
				$sendto = new PrivateChannelCommandReply($this, $channel);
				$this->commandManager->process($type, $message, $sender, $sendto);
			}
		} else {  // ext priv group message
			$type = "extpriv";
			$eventObj->type = $type;

			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle a message on a public channel
	 *
	 * @param mixed[] $args [
	 * 	0 => GroupID of the channel,
	 * 	1 => UserID of the sender
	 * 	2 => The message
	 * ]
	 * @return void
	 */
	public function processPublicChannelMessage($args) {
		$charId = $args[1];
		$sender	 = $this->lookup_user($charId);
		$message = $args[2];
		$channel = $this->get_gname($args[0]);

		$eventObj = new Event();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->log('DEBUG', "AOCP_GROUP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");

		if (in_array($channel, $this->channelsToIgnore)) {
			return;
		}

		$orgId = $this->getOrgId($args[0]);

		// don't log tower messages with rest of chat messages
		if ($channel != "All Towers" && $channel != "Tower Battle Outcome" && (!$orgId || $this->settingManager->get('guild_channel_status') == 1)) {
			$this->logger->logChat($channel, $sender, $message);
		} else {
			$this->logger->log('DEBUG', "[" . $channel . "]: " . $message);
		}

		if ($this->util->isValidSender($sender)) {
			// ignore messages that are sent from the bot self
			if ($sender == $this->vars["name"]) {
				return;
			}
			if ($this->banController->isBanned($charId)) {
				return;
			}
		}

		if ($channel == "All Towers" || $channel == "Tower Battle Outcome") {
			$eventObj->type = "towers";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($channel == "Org Msg") {
			$eventObj->type = "orgmsg";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($orgId && $this->settingManager->get('guild_channel_status') == 1) {
			$type = "guild";
			$sendto = 'guild';

			$eventObj->type = $type;

			$this->eventManager->fireEvent($eventObj);

			if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
				$message = substr($message, 1);
				$sendto = new GuildChannelCommandReply($this);
				$this->commandManager->process($type, $message, $sender, $sendto);
			}
		}
	}

	/**
	 * Handle an invite to a private channel
	 *
	 * @param int[] $args [0 => UserID of the channel]
	 * @return void
	 */
	public function processPrivateChannelInvite($args) {
		$type = "extjoinprivrequest"; // Set message type.
		$uid = $args[0];
		$sender = $this->lookup_user($uid);

		$eventObj = new Event();
		$eventObj->sender = $sender;
		$eventObj->type = $type;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_INVITE => sender: '$sender'");

		$this->logger->logChat("Priv Channel Invitation", -1, "$sender channel invited.");

		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Register a module
	 *
	 * In order to later easily find a module, it registers here
	 * and other modules can get the instance by querying for $name
	 *
	 * @param string $name The name by which this module shall be known
	 * @param \Object $obj The object that's registering. Must provide an attribute $moduleName
	 * @return void
	 */
	public function registerInstance($name, $obj) {
		$this->logger->log('DEBUG', "Registering instance name '$name' for module '{$obj->moduleName}'");
		$moduleName = $obj->moduleName;

		// register settings annotated on the class
		$reflection = new ReflectionAnnotatedClass($obj);
		foreach ($reflection->getProperties() as $property) {
			/** @var \Addendum\ReflectionAnnotatedProperty $property */
			if ($property->hasAnnotation('Setting')) {
				$this->settingManager->add(
					$moduleName,
					$property->getAnnotation('Setting')->value,
					$property->getAnnotation('Description')->value,
					$property->getAnnotation('Visibility')->value,
					$property->getAnnotation('Type')->value,
					$obj->{$property->name},
					@$property->getAnnotation('Options')->value,
					@$property->getAnnotation('Intoptions')->value,
					@$property->getAnnotation('AccessLevel')->value,
					@$property->getAnnotation('Help')->value
				);
			}
		}

		// register commands, subcommands, and events annotated on the class
		$commands = array();
		$subcommands = array();
		foreach ($reflection->getAllAnnotations() as $annotation) {
			if ($annotation instanceof DefineCommand) {
				if (!$annotation->command) {
					$this->logger->log('WARN', "Cannot parse @DefineCommand annotation in '$name'.");
				}
				$command = $annotation->command;
				$definition = array(
					'channels'      => $annotation->channels,
					'defaultStatus' => $annotation->defaultStatus,
					'accessLevel'   => $annotation->accessLevel,
					'description'   => $annotation->description,
					'help'          => $annotation->help,
					'handlers'      => array()
				);
				list($parentCommand, $subCommand) = explode(" ", $command, 2);
				if ($subCommand) {
					$definition['parentCommand'] = $parentCommand;
					$subcommands[$command] = $definition;
				} else {
					$commands[$command] = $definition;
				}
				// register command alias if defined
				if ($annotation->alias) {
					$this->commandAlias->register($moduleName, $command, $annotation->alias);
				}
			}
		}

		foreach ($reflection->getMethods() as $method) {
			/** @var \Addendum\ReflectionAnnotatedMethod $method */
			if ($method->hasAnnotation('Setup')) {
				if (call_user_func(array($obj, $method->name)) === false) {
					$this->logger->log('ERROR', "Failed to call setup handler for '$name'");
				}
			} elseif ($method->hasAnnotation('HandlesCommand')) {
				$commandName = $method->getAnnotation('HandlesCommand')->value;
				$methodName  = $method->name;
				$handlerName = "{$name}.{$method->name}";
				if (isset($commands[$commandName])) {
					$commands[$commandName]['handlers'][] = $handlerName;
				} elseif (isset($subcommands[$commandName])) {
					$subcommands[$commandName]['handlers'][] = $handlerName;
				} else {
					$this->logger->log('WARN', "Cannot handle command '$commandName' as it is not defined with @DefineCommand in '$name'.");
				}
			} elseif ($method->hasAnnotation('Event')) {
				foreach ($method->getAllAnnotations('Event') as $eventAnnotation) {
					$this->eventManager->register(
						$moduleName,
						$eventAnnotation->value,
						$name . '.' . $method->name,
						@$method->getAnnotation('Description')->value,
						@$method->getAnnotation('Help')->value,
						@$method->getAnnotation('DefaultStatus')->value
					);
				}
			}
		}

		foreach ($commands as $command => $definition) {
			if (count($definition['handlers']) == 0) {
				$this->logger->log('ERROR', "No handlers defined for command '$command' in module '$moduleName'.");
				continue;
			}
			$this->commandManager->register(
				$moduleName,
				$definition['channels'],
				implode(',', $definition['handlers']),
				$command,
				$definition['accessLevel'],
				$definition['description'],
				$definition['help'],
				$definition['defaultStatus']
			);
		}

		foreach ($subcommands as $subcommand => $definition) {
			if (count($definition['handlers']) == 0) {
				$this->logger->log('ERROR', "No handlers defined for subcommand '$subcommand' in module '$moduleName'.");
				continue;
			}
			$this->subcommandManager->register(
				$moduleName,
				$definition['channels'],
				implode(',', $definition['handlers']),
				$subcommand,
				$definition['accessLevel'],
				$definition['parentCommand'],
				$definition['description'],
				$definition['help'],
				$definition['defaultStatus']
			);
		}
	}

	/**
	 * Call the setup method for an object
	 *
	 * @param string $name The name by which the module is registered
	 * @param \Object $obj The object
	 * @return void
	 */
	public function callSetupMethod($name, $obj) {
		$reflection = new ReflectionAnnotatedClass($obj);
		foreach ($reflection->getMethods() as $method) {
			/** @var \Addendum\ReflectionAnnotatedMethod $method */
			if ($method->hasAnnotation('Setup')) {
				if (call_user_func(array($obj, $method->name)) === false) {
					$this->logger->log('ERROR', "Failed to call setup handler for '$name'");
				}
			}
		}
	}

	/**
	 * Get the amount of people allowed on our friendlist
	 *
	 * @return int
	 */
	public function getBuddyListSize() {
		return $this->buddyListSize;
	}

	/**
	 * Get the OrgID for a ChannelID
	 *
	 * @param int $channelId The ChannelID for which to find the OrgID
	 * @return int|bool The OrgID or false if there was an error calculating it
	 */
	public function getOrgId($channelId) {
		$b = unpack("C*", $channelId);
		if ($b[1] == 3) {
			return ($b[2] << 24) + ($b[3] << 16) + ($b[4] << 8) + ($b[5]);
		} else {
			return false;
		}
	}

	/**
	 * Tells when the bot is logged on and all the start up events have finished
	 *
	 * @return bool
	 */
	public function isReady() {
		return $this->ready;
	}

	/**
	 * Check if a private channel is this bot's private channel
	 *
	 * @param string $channel
	 * @return boolean
	 */
	public function isDefaultPrivateChannel($channel) {
		return $channel == $this->setting->default_private_channel;
	}
}
