<?php declare(strict_types=1);

namespace Nadybot\Core;

use Addendum\ReflectionAnnotatedClass;
use Nadybot\Core\Annotations\DefineCommand;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\Modules\LIMITS\LimitsController;
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Core\DBSchema\{
	CmdCfg,
	EventCfg,
	HlpCfg,
	Setting,
};

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

/**
 * @Instance("chatBot")
 */
class Nadybot extends AOChat {

	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public BanController $banController;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public LimitsController $limitsController;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public RelayController $relayController;

	/** @Inject */
	public SettingObject $setting;

	/** @Logger("Core") */
	public LoggerWrapper $logger;

	public BotRunner $runner;

	public bool $ready = false;

	/**
	 * Names of players in our private channel
	 * @var array<string,bool>
	 **/
	public array $chatlist = [];

	/**
	 * The rank for each member of this bot's guild/org
	 * [(string)name => (int)rank]
	 * @var array<string,int> $guildmembers
	 */
	public array $guildmembers = [];

	/**
	 * The configuration variables of this bot as given in the config file
	 * [(string)var => (mixed)value]
	 *
	 * @var array<string,mixed> $vars
	 */
	public array $vars;

	/**
	 * How many buddies can this bot hold
	 */
	private int $buddyListSize = 0;

	/**
	 * A list of channels that we ignore messages from
	 *
	 * Ignore Messages from Vicinity/IRRK New Wire/OT OOC/OT Newbie OOC...
	 *
	 * @var string[] $channelsToIgnore
	 */
	public array $channelsToIgnore = [
		'IRRK News Wire', 'OT OOC', 'OT Newbie OOC', 'OT shopping 11-50',
		'Tour Announcements', 'Neu. Newbie OOC', 'Neu. shopping 11-50', 'Neu. OOC', 'Clan OOC',
		'Clan Newbie OOC', 'Clan shopping 11-50', 'OT German OOC', 'Clan German OOC', 'Neu. German OOC'
	];

	protected int $started = 0;

	/**
	 * Initialize the bot
	 *
	 * @param array<string,mixed> $vars The configuration variables of the bot
	 */
	public function init(BotRunner $runner, array &$vars): void {
		$this->started = time();
		$this->runner = $runner;
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
			"`admin` VARCHAR(30), ".
			"`description` VARCHAR(75) DEFAULT 'none', ".
			"`verify` INT DEFAULT '0', ".
			"`status` INT DEFAULT '0', ".
			"`dependson` VARCHAR(25) DEFAULT 'none', ".
			"`help` VARCHAR(255)".
			")"
		);
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->db->exec("ALTER TABLE cmdcfg_<myname> MODIFY COLUMN `admin` VARCHAR(30)");
		}
		$this->db->exec("CREATE INDEX IF NOT EXISTS `cmdcfg_<myname>_verify_idx` ON `cmdcfg_<myname>`(`verify`)");
		$this->db->exec("CREATE INDEX IF NOT EXISTS `cmdcfg_<myname>_cmd_idx` ON `cmdcfg_<myname>`(`cmd`)");
		$this->db->exec("CREATE INDEX IF NOT EXISTS `cmdcfg_<myname>_type_idx` ON `cmdcfg_<myname>`(`type`)");
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `eventcfg_<myname>` (".
			"`module` VARCHAR(50), ".
			"`type` VARCHAR(50), ".
			"`file` VARCHAR(100), ".
			"`description` VARCHAR(75) DEFAULT 'none', ".
			"`verify` INT DEFAULT '0', ".
			"`status` INT DEFAULT '0', ".
			"`help` VARCHAR(255)".
			")"
		);
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->db->exec("ALTER TABLE `eventcfg_<myname>` CHANGE `type` `type` VARCHAR(50)");
			$this->db->exec("ALTER TABLE `eventcfg_<myname>` CHANGE `file` `file` VARCHAR(100)");
		}
		$this->db->exec("CREATE INDEX IF NOT EXISTS `eventcfg_<myname>_type_idx` ON `eventcfg_<myname>`(`type`)");
		$this->db->exec("CREATE INDEX IF NOT EXISTS `eventcfg_<myname>_file_idx` ON `eventcfg_<myname>`(`file`)");
		$this->db->exec("CREATE INDEX IF NOT EXISTS `eventcfg_<myname>_module_idx` ON `eventcfg_<myname>`(`module`)");
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
		$this->db->exec("CREATE INDEX IF NOT EXISTS `settings_<myname>_name_idx` ON `settings_<myname>`(`name`)");
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
		$this->db->exec("CREATE INDEX IF NOT EXISTS `hlpcfg_<myname>_name_idx` ON `hlpcfg_<myname>`(`name`)");
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS cmd_alias_<myname> (".
			"`cmd` VARCHAR(255) NOT NULL, ".
			"`module` VARCHAR(50), ".
			"`alias` VARCHAR(25) NOT NULL, ".
			"`status` INT DEFAULT '0'".
			")"
		);
		$this->db->exec("CREATE INDEX IF NOT EXISTS `cmd_alias_<myname>_alias_idx` ON `cmd_alias_<myname>`(`alias`)");

		// Prepare command/event settings table
		$this->db->exec("UPDATE `cmdcfg_<myname>` SET `verify` = 0");
		$this->db->exec("UPDATE `eventcfg_<myname>` SET `verify` = 0");
		$this->db->exec("UPDATE `settings_<myname>` SET `verify` = 0");
		$this->db->exec("UPDATE `hlpcfg_<myname>` SET `verify` = 0");
		$this->db->exec("UPDATE `eventcfg_<myname>` SET `status` = 1 WHERE `type` = 'setup'");

		// To reduce queries load core items into memory
		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, "SELECT * FROM `cmdcfg_<myname>` WHERE `cmdevent` = 'subcmd'");
		foreach ($data as $row) {
			$this->existing_subcmds[$row->type][$row->cmd] = true;
		}

		/** @var EventCfg[] $data */
		$data = $this->db->fetchAll(EventCfg::class, "SELECT * FROM `eventcfg_<myname>`");
		foreach ($data as $row) {
			$this->existing_events[$row->type][$row->file] = true;
		}

		/** @var HlpCfg[] $data */
		$data = $this->db->fetchAll(HlpCfg::class, "SELECT * FROM `hlpcfg_<myname>`");
		foreach ($data as $row) {
			$this->existing_helps[$row->name] = true;
		}

		/** @var Setting[] $data */
		$data = $this->db->fetchAll(Setting::class, "SELECT * FROM `settings_<myname>`");
		foreach ($data as $row) {
			$this->existing_settings[$row->name] = true;
		}

		$this->db->beginTransaction();
		$allClasses = get_declared_classes();
		foreach ($allClasses as $class) {
			$this->registerEvents($class);
		}
		$this->db->commit();
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
		$this->db->exec("DELETE FROM `cmdcfg_<myname>` WHERE `verify` = 0");
		$this->db->exec("DELETE FROM `eventcfg_<myname>` WHERE `verify` = 0");
		$this->db->exec("DELETE FROM `settings_<myname>` WHERE `verify` = 0");
		$this->db->exec("DELETE FROM `hlpcfg_<myname>` WHERE `verify` = 0");

		$this->commandManager->loadCommands();
		$this->subcommandManager->loadSubcommands();
		$this->commandAlias->load();
		$this->eventManager->loadEvents();
	}

	/**
	 * Connect to AO chat servers
	 */
	public function connectAO(string $login, string $password, string $server, int $port): void {
		// Begin the login process
		$this->logger->log('INFO', "Connecting to AO Server...({$server}:{$port})");
		if (null === $this->connect($server, $port)) {
			$this->logger->log('ERROR', "Connection failed! Please check your Internet connection and firewall.");
			sleep(10);
			die();
		}

		$this->logger->log('INFO', "Authenticate login data...");
		if (null === $this->authenticate($login, $password)) {
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
	 */
	public function run(): void {
		$loop = new EventLoop();
		Registry::injectDependencies($loop);

		$continue = true;
		$signalHandler = function ($sigNo) use (&$continue) {
			$this->logger->log('INFO', 'Shutdown requested.');
			$continue = false;
		};
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} elseif (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, $signalHandler);
			pcntl_signal(SIGTERM, $signalHandler);
		} else {
			$this->logger->log('ERROR', 'You need to have the pcntl extension on Linux');
			exit(1);
		}
		$callDispatcher = true;
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			$callDispatcher = false;
		}

		while ($continue) {
			$loop->execSingleLoop();
			if ($callDispatcher && function_exists('pcntl_signal_dispatch')) {
				pcntl_signal_dispatch();
			}
		}
		$this->logger->log('INFO', 'Graceful shutdown.');
	}

	/**
	 * Process all packets in an endless loop
	 */
	public function processAllPackets(): void {
		while ($this->processNextPacket()) {
		}
	}

	/**
	 * Wait for the next packet and process it
	 */
	public function processNextPacket(): bool {
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
	 * @param boolean $disableRelay Set to true to disable relaying the message into the org/guild channel
	 * @param string $group Name of the private group to send message into or null for the bot's own
	 */
	public function sendPrivate($message, bool $disableRelay=false, string $group=null): void {
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendPrivate($page, $disableRelay, $group);
			}
			return;
		}

		if ($group === null) {
			$group = $this->setting->default_private_channel;
		}

		$message = $this->text->formatMessage($origMsg = $message);
		$senderLink = $this->text->makeUserlink($this->vars['name']);
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		$guestColorChannel = $this->settingManager->get('guest_color_channel');
		$privColor = $this->settingManager->get('default_priv_color');

		$this->send_privgroup($group, $privColor.$message);
		$event = new AOChatEvent();
		$event->type = "sendpriv";
		$event->channel = $group;
		$event->message = $origMsg;
		$event->sender = $this->vars["name"];
		$this->eventManager->fireEvent($event, $disableRelay);
		if ($this->isDefaultPrivateChannel($group)) {
			// relay to guild channel
			if (!$disableRelay
				&& $this->settingManager->getBool('guild_channel_status')
				&& $this->settingManager->getBool("guest_relay")
				&& $this->settingManager->getBool("guest_relay_commands")
			) {
				$this->send_guild("</font>{$guestColorChannel}[Guest]</font> {$senderLink}: {$privColor}$message</font>", "\0");
			}

			// relay to bot relay
			if (!$disableRelay && $this->settingManager->getString("relaybot") !== "Off" && $this->settingManager->getBool("bot_relay_commands")) {
				if (isset($this->vars["my_guild"]) && strlen($this->vars["my_guild"])) {
					$this->relayController->sendMessageToRelay(
						"grc <v2><relay_guild_tag_color>[{$guildNameForRelay}]</end> ".
						"<relay_guest_tag_color>[Guest]</end> ".
						"{$senderLink}: <relay_bot_color>$message</end>"
					);
				} else {
					$this->relayController->sendMessageToRelay(
						"grc <v2><relay_raidbot_tag_color>[<myname>]</end> ".
						"{$senderLink}: <relay_bot_color>$message</end>"
					);
				}
			}
		}
	}

	/**
	 * Send one or more messages into the org/guild channel
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param boolean $disableRelay Set to true to disable relaying the message into the bot's private channel
	 * @param int $priority The priority of the message or medium if unset
	 * @return void
	 */
	public function sendGuild($message, bool $disableRelay=false, int $priority=null): void {
		if ($this->settingManager->get('guild_channel_status') != 1) {
			return;
		}

		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendGuild($page, $disableRelay, $priority);
			}
			return;
		}

		$priority ??= AOC_PRIORITY_MED;

		$message = $this->text->formatMessage($origMsg = $message);
		$senderLink = $this->text->makeUserlink($this->vars['name']);
		$guildNameForRelay = $this->relayController->getGuildAbbreviation();
		$guestColorChannel = $this->settingManager->get('guest_color_channel');
		$guildColor = $this->settingManager->get("default_guild_color");

		$this->send_guild($guildColor.$message, "\0", $priority);
		$event = new AOChatEvent();
		$event->type = "sendguild";
		$event->channel = $this->vars["my_guild"];
		$event->message = $origMsg;
		$event->sender = $this->vars["name"];
		$this->eventManager->fireEvent($event, $disableRelay);

		// relay to private channel
		if (!$disableRelay
			&& $this->settingManager->getBool("guest_relay")
			&& $this->settingManager->getBool("guest_relay_commands")
		) {
			$this->send_privgroup($this->setting->default_private_channel, "</font>{$guestColorChannel}[{$guildNameForRelay}]</font> {$senderLink}: {$guildColor}$message</font>");
		}

		// relay to bot relay
		if (!$disableRelay
			&& $this->settingManager->get("relaybot") !== "Off"
			&& $this->settingManager->getBool("bot_relay_commands")
		) {
			$this->relayController->sendMessageToRelay("grc <v2><relay_guild_tag_color>[{$guildNameForRelay}]</end> {$senderLink}: <relay_bot_color>$message</end>");
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
	public function sendTell($message, string $character, int $priority=null, bool $formatMessage=true): void {
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
		$event = new AOChatEvent();
		$event->type = "sendmsg";
		$event->channel = $character;
		$event->message = $message;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Send a mass message via the chatproxy to another player/bot
	 */
	public function sendMassTell($message, string $character, int $priority=null, bool $formatMessage=true): void {
		// If we're not using a chat proxy, this doesn't do anything
		if (($this->vars["use_proxy"]??0) == 0) {
			$this->sendTell(...func_get_args());
			return;
		}
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendMassTell($page, $character, $priority);
			}
			return;
		}

		$priority ??= AOC_PRIORITY_HIGH;

		if ($formatMessage) {
			$message = $this->text->formatMessage($message);
			$tellColor = $this->settingManager->get("default_tell_color");
		}

		$this->logger->logChat("Out. Msg.", $character, $message);
		$this->send_tell($character, $tellColor.$message, "spam", $priority);
	}

	/**
	 * Send one or more messages into a public channel
	 *
	 * @param string|string[] $message One or more messages to send
	 * @param string $channel Name of the channel to send the message to
	 * @param int $priority The priority of the message or medium if unset
	 */
	public function sendPublic($message, string $channel, int $priority=null): void {
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
	 */
	public function processCommandArgs(?string &$type, string &$admin): bool {
		if ($type === null || $type == "") {
			$type = ["msg", "priv", "guild"];
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
	 */
	public function process_packet(AOChatPacket $packet): void {
		try {
			$this->process_all_packets($packet);

			// event handlers
			switch ($packet->type) {
				case AOCP_LOGIN_OK: // 5
					$this->buddyListSize += 1000;
					break;
				case AOCP_GROUP_ANNOUNCE: // 60
					$this->processGroupAnnounce(...$packet->args);
					break;
				case AOCP_PRIVGRP_CLIJOIN: // 55, Incoming player joined private chat
					$this->processPrivateChannelJoin(...$packet->args);
					break;
				case AOCP_PRIVGRP_CLIPART: // 56, Incoming player left private chat
					$this->processPrivateChannelLeave(...$packet->args);
					break;
				case AOCP_BUDDY_ADD: // 40, Incoming buddy logon or off
					$this->processBuddyUpdate(...$packet->args);
					break;
				case AOCP_BUDDY_REMOVE: // 41, Incoming buddy removed
					$this->processBuddyRemoved(...$packet->args);
					break;
				case AOCP_MSG_PRIVATE: // 30, Incoming Msg
					$this->processPrivateMessage(...$packet->args);
					break;
				case AOCP_PRIVGRP_MESSAGE: // 57, Incoming priv message
					$this->processPrivateChannelMessage(...$packet->args);
					break;
				case AOCP_GROUP_MESSAGE: // 65, Public and guild channels
					$this->processPublicChannelMessage(...$packet->args);
					break;
				case AOCP_PRIVGRP_INVITE: // 50, private channel invite
					$this->processPrivateChannelInvite(...$packet->args);
					break;
			}
		} catch (StopExecutionException $e) {
			$this->logger->log('DEBUG', 'Execution stopped prematurely', $e);
		}
	}

	/**
	 * Fire associated events for a received packet
	 */
	public function process_all_packets(AOChatPacket $packet): void {
		// fire individual packets event
		$eventObj = new PacketEvent();
		$eventObj->type = "packet({$packet->type})";
		$eventObj->packet = $packet;
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Handle an incoming AOCP_GROUP_ANNOUNCE packet
	 */
	public function processGroupAnnounce(string $groupId, string $groupName): void {
		$orgId = $this->getOrgId($groupId);
		$this->logger->log('DEBUG', "AOCP_GROUP_ANNOUNCE => name: '$groupName'");
		if ($orgId) {
			$this->vars["my_guild_id"] = $orgId;
		}
	}

	/**
	 * Handle a player joining a private group
	 */
	public function processPrivateChannelJoin(int $channelId, int $userId): void {
		$eventObj = new AOChatEvent();
		$channel = $this->lookup_user($channelId);
		$sender = $this->lookup_user($userId);
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_CLIJOIN => channel: '$channel' sender: '$sender'");

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "joinpriv";

			$this->logger->logChat("Priv Group", -1, "$sender joined the channel.");

			// Remove sender if they are banned
			if ($this->banController->isBanned($userId)) {
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
	 */
	public function processPrivateChannelLeave(int $channelId, int $userId) {
		$eventObj = new AOChatEvent();
		$channel = $this->lookup_user($channelId);
		$sender = $this->lookup_user($userId);
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
	 */
	public function processBuddyUpdate(int $userId, int $status): void {
		$sender = $this->lookup_user($userId);

		$eventObj = new UserStateEvent();
		$eventObj->sender = $sender;

		$this->logger->log('DEBUG', "AOCP_BUDDY_ADD => sender: '$sender' status: '$status'");

		$this->buddylistManager->update($userId, (bool)$status);

		// Ignore Logon/Logoff from other bots or phantom logon/offs
		if ($sender === "") {
			return;
		}

		// Status => 0: logoff  1: logon
		$eventObj->type = "logon";
		if ($status === 0) {
			$eventObj->type = "logoff";
			$this->logger->log('DEBUG', "$sender logged off");
		} else {
			$this->logger->log('DEBUG', "$sender logged on");
		}
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Handle that a friend was removed from the friendlist
	 */
	public function processBuddyRemoved(int $userId): void {
		$sender = $this->lookup_user($userId);

		$this->logger->log('DEBUG', "AOCP_BUDDY_REMOVE => sender: '$sender'");

		$this->buddylistManager->updateRemoved($userId);
	}

	/**
	 * Handle an incoming tell
	 */
	public function processPrivateMessage(int $senderId, string $message): void {
		$type = "msg";
		$sender = $this->lookup_user($senderId);

		$this->logger->log('DEBUG', "AOCP_MSG_PRIVATE => sender: '$sender' message: '$message'");

		// Removing tell color
		if (preg_match("/^<font color='#([0-9a-f]+)'>(.+)$/si", $message, $arr)) {
			$message = $arr[2];
		}

		$eventObj = new AOChatEvent();
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

		if ($this->banController->isBanned($senderId)) {
			return;
		}

		$this->eventManager->fireEvent($eventObj);

		// remove the symbol if there is one
		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
		}

		// check tell limits
		$this->limitsController->checkAndExecute(
			$sender,
			$message,
			function() use ($sender, $type, $message): void {
				$sendto = new PrivateMessageCommandReply($this, $sender);
				$this->commandManager->process($type, $message, $sender, $sendto);
			}
		);
	}

	/**
	 * Handle a message on a private channel
	 */
	public function processPrivateChannelMessage(int $channelId, int $senderId, string $message): void {
		$channel = $this->lookup_user($channelId);
		$sender = $this->lookup_user($senderId);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");
		$this->logger->logChat($channel, $sender, $message);

		if ($sender == $this->vars["name"] || $this->banController->isBanned($senderId)) {
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
	 */
	public function processPublicChannelMessage(string $channelId, int $senderId, string $message): void {
		$channel = $this->get_gname($channelId);
		$sender  = $this->lookup_user($senderId);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->log('DEBUG', "AOCP_GROUP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");

		if (in_array($channel, $this->channelsToIgnore)) {
			return;
		}

		$orgId = $this->getOrgId($channelId);

		// don't log tower messages with rest of chat messages
		if ($channel != "All Towers" && $channel != "Tower Battle Outcome" && (!$orgId || $this->settingManager->getBool('guild_channel_status'))) {
			$this->logger->logChat($channel, $sender, $message);
		} else {
			$this->logger->log('DEBUG', "[" . $channel . "]: " . $message);
		}

		if ($this->util->isValidSender($sender)) {
			// ignore messages that are sent from the bot self
			if ($sender == $this->vars["name"]) {
				return;
			}
			if ($this->banController->isBanned($senderId)) {
				return;
			}
		}

		if ($channel == "All Towers" || $channel == "Tower Battle Outcome") {
			$eventObj->type = "towers";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($channel == "Org Msg") {
			$eventObj->type = "orgmsg";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($orgId && $this->settingManager->getBool('guild_channel_status')) {
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
	 */
	public function processPrivateChannelInvite(int $channelId): void {
		$type = "extjoinprivrequest"; // Set message type.
		$sender = $this->lookup_user($channelId);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->type = $type;

		$this->logger->log('DEBUG', "AOCP_PRIVGRP_INVITE => sender: '$sender'");

		$this->logger->logChat("Priv Channel Invitation", -1, "$sender channel invited.");

		$this->eventManager->fireEvent($eventObj);
	}

	public function registerEvents(string $class): void {
		$reflection = new ReflectionAnnotatedClass($class);

		if (!$reflection->hasAnnotation('ProvidesEvent')) {
			return;
		}
		foreach ($reflection->getAllAnnotations('ProvidesEvent') as $eventAnnotation) {
			$this->eventManager->addEventType($eventAnnotation->value);
		}
	}

	/**
	 * Register a module
	 *
	 * In order to later easily find a module, it registers here
	 * and other modules can get the instance by querying for $name
	 */
	public function registerInstance(string $name, object $obj): void {
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
		$commands = [];
		$subcommands = [];
		foreach ($reflection->getAllAnnotations() as $annotation) {
			if ($annotation instanceof DefineCommand) {
				if (!$annotation->command) {
					$this->logger->log('WARN', "Cannot parse @DefineCommand annotation in '$name'.");
				}
				$command = $annotation->command;
				$definition = [
					'channels'      => $annotation->channels,
					'defaultStatus' => $annotation->defaultStatus,
					'accessLevel'   => $annotation->accessLevel,
					'description'   => $annotation->description,
					'help'          => $annotation->help,
					'handlers'      => []
				];
				[$parentCommand, $subCommand] = explode(" ", $command . " ", 2);
				if ($subCommand !== "") {
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
				if (call_user_func([$obj, $method->name]) === false) {
					$this->logger->log('ERROR', "Failed to call setup handler for '$name'");
				}
			} elseif ($method->hasAnnotation('HandlesCommand')) {
				$commandName = $method->getAnnotation('HandlesCommand')->value;
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
					$defaultStatus = @$method->getAnnotation('DefaultStatus')->value;
					$this->eventManager->register(
						$moduleName,
						$eventAnnotation->value,
						$name . '.' . $method->name,
						@$method->getAnnotation('Description')->value,
						@$method->getAnnotation('Help')->value,
						isset($defaultStatus) ? (int)$defaultStatus : null
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
				(string)$command,
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
	 */
	public function callSetupMethod(string $name, object $obj): void {
		$reflection = new ReflectionAnnotatedClass($obj);
		foreach ($reflection->getMethods() as $method) {
			/** @var \Addendum\ReflectionAnnotatedMethod $method */
			if ($method->hasAnnotation('Setup')) {
				if (call_user_func([$obj, $method->name]) === false) {
					$this->logger->log('ERROR', "Failed to call setup handler for '$name'");
				}
			}
		}
	}

	/**
	 * Get the amount of people allowed on our friendlist
	 */
	public function getBuddyListSize(): int {
		return $this->buddyListSize;
	}

	/**
	 * Get the OrgID for a ChannelID or null if not an org channel
	 */
	public function getOrgId(string $channelId): ?int {
		$b = unpack("Ctype/Nid", $channelId);
		if ($b['type'] === 3) {
			return $b['id'];
		}
		return null;
	}

	/**
	 * Tells when the bot is logged on and all the start up events have finished
	 */
	public function isReady(): bool {
		return $this->ready;
	}

	/**
	 * Check if a private channel is this bot's private channel
	 */
	public function isDefaultPrivateChannel(string $channel): bool {
		return $channel == $this->setting->default_private_channel;
	}

	public function getUptime(): int {
		return time() - $this->started;
	}
}
