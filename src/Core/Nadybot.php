<?php declare(strict_types=1);

namespace Nadybot\Core;

use ReflectionClass;
use Nadybot\Core\Modules\BAN\BanController;
use Nadybot\Core\Modules\LIMITS\LimitsController;
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Core\DBSchema\{
	Audit,
	CmdCfg,
	EventCfg,
	HlpCfg,
	Setting,
};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;
use Exception;
use InvalidArgumentException;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Channels\OrgChannel;
use Nadybot\Core\Channels\PrivateChannel;
use Nadybot\Core\Channels\PublicChannel;
use Nadybot\Core\Channels\PrivateMessage;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingHandler as CoreSettingHandler;
use Throwable;

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

#[NCA\Instance("chatBot")]
class Nadybot extends AOChat {

	public const PING_IDENTIFIER = "Nadybot";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public BanController $banController;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public LimitsController $limitsController;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public SettingObject $setting;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public BotRunner $runner;

	public bool $ready = false;

	/**
	 * Names of players in our private channel
	 * @var array<string,bool>
	 **/
	public array $chatlist = [];

	/**
	 * Names of private channels we're in
	 * @var array<string,bool>
	 **/
	public array $privateChats = [];

	/** @var array<string,array<string,bool>> */
	public array $existing_subcmds = [];

	/** @var array<string,array<string,bool>> */
	public array $existing_events = [];

	/** @var array<string,bool> */
	public array $existing_helps = [];

	/** @var array<string,bool> */
	public array $existing_settings = [];

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

	protected int $numSpamMsgsSent = 0;

	public ProxyCapabilities $proxyCapabilities;

	/**
	 * Initialize the bot
	 *
	 * @param array<string,mixed> $vars The configuration variables of the bot
	 */
	public function init(BotRunner $runner, array &$vars): void {
		$this->started = time();
		$this->runner = $runner;
		$this->vars = $vars;
		$this->proxyCapabilities = new ProxyCapabilities();

		// Set startup time
		$this->vars["startup"] = time();

		$this->logger->info('Initializing bot');

		// Prepare command/event settings table
		$this->db->table(CommandManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(EventManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(SettingManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(HelpManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(EventManager::DB_TABLE)->where("type", "setup")->update(["verify" => 1]);

		// To reduce queries load core items into memory
		$this->db->table(CommandManager::DB_TABLE)->where("cmdevent", "subcmd")->asObj(CmdCfg::class)
			->each(function(CmdCfg $row) {
				$this->existing_subcmds[$row->type][$row->cmd] = true;
			});

		$this->db->table(EventManager::DB_TABLE)->asObj(EventCfg::class)
			->each(function(EventCfg $row) {
				$this->existing_events[$row->type??""][$row->file??""] = true;
			});

		$this->db->table(HelpManager::DB_TABLE)->asObj(HlpCfg::class)
			->each(function(HlpCfg $row) {
				$this->existing_helps[$row->name] = true;
			});

		$this->existing_settings = [];
		$this->db->table(SettingManager::DB_TABLE)->asObj(Setting::class)
			->each(function(Setting $row) {
				$this->existing_settings[$row->name] = true;
			});

		$this->db->beginTransaction();
		$allClasses = get_declared_classes();
		foreach ($allClasses as $class) {
			$this->registerEvents($class);
			$this->registerSettingHandlers($class);
		}
		$this->db->commit();
		$this->db->beginTransaction();
		foreach (Registry::getAllInstances() as $name => $instance) {
			if (isset($instance->moduleName)) {
				$this->registerInstance($name, $instance);
			} else {
				$this->callSetupMethod($name, $instance);
			}
			if (!$this->db->inTransaction()) {
				$this->db->beginTransaction();
			}
		}
		$this->db->commit();

		//Delete old entries in the DB
		$this->db->table(CommandManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(EventManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(SettingManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(HelpManager::DB_TABLE)->where("verify", 0)->delete();

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
		$this->logger->notice("Connecting to AO Server...({$server}:{$port})");
		if (!$this->connect($server, $port)) {
			$this->logger->error("Connection failed! Please check your Internet connection and firewall.");
			sleep(10);
			die();
		}

		$this->logger->notice("Authenticate login data...");
		if (null === $this->authenticate($login, $password)) {
			$this->logger->error("Authentication failed! Invalid username or password.");
			sleep(10);
			die();
		}

		$this->logger->notice("Logging in {$this->vars["name"]}...");
		if (false === $this->login($this->vars["name"])) {
			$this->logger->error("Character selection failed! Could not login on as character '{$this->vars["name"]}'.");
			sleep(10);
			die();
		}

		if (($this->vars["use_proxy"]??0) == 1) {
			$this->queryProxyFeatures();
		}

		$this->buddyListSize += 1000;
		$this->logger->notice("All Systems ready!");
		$pc = new PrivateChannel($this->vars["name"]);
		Registry::injectDependencies($pc);
		$this->messageHub
			->registerMessageReceiver($pc)
			->registerMessageEmitter($pc);

		$pm = new PrivateMessage();
		Registry::injectDependencies($pm);
		$this->messageHub
			->registerMessageReceiver($pm)
			->registerMessageEmitter($pm);
	}

	/**
	 * The main endless-loop of the bot
	 */
	public function run(): void {
		$loop = new EventLoop();
		Registry::injectDependencies($loop);

		$continue = true;
		$signalHandler = function () use (&$continue): void {
			$this->logger->notice('Shutdown requested.');
			$continue = false;
		};
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} elseif (function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, $signalHandler);
			pcntl_signal(SIGTERM, $signalHandler);
		} else {
			$this->logger->error('You need to have the pcntl extension on Linux');
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
		$this->logger->notice('Graceful shutdown.');
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
	public function sendPrivate($message, bool $disableRelay=false, string $group=null, bool $addDefaultColor=true): void {
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
		$privColor = "";
		if ($addDefaultColor) {
			$privColor = $this->settingManager->getString('default_priv_color') ?? "";
		}

		$this->send_privgroup($group, $privColor.$message);
		$event = new AOChatEvent();
		$event->type = "sendpriv";
		$event->channel = $group;
		$event->message = $origMsg;
		$event->sender = $this->vars["name"];
		$this->eventManager->fireEvent($event, $disableRelay);
		if (!$disableRelay) {
			$rMessage = new RoutableMessage($origMsg);
			$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
			$label = null;
			if (isset($this->vars["my_guild"]) && strlen($this->vars["my_guild"])) {
				$label = "Guest";
			}
			$rMessage->prependPath(new Source(Source::PRIV, $this->char->name, $label));
			$this->messageHub->handle($rMessage);
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
	public function sendGuild($message, bool $disableRelay=false, int $priority=null, bool $addDefaultColor=true): void {
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

		$priority ??= $this->chatqueue::PRIORITY_MED;

		$message = $this->text->formatMessage($origMsg = $message);
		$guildColor = "";
		if ($addDefaultColor) {
			$guildColor = $this->settingManager->getString("default_guild_color")??"";
		}

		$this->send_guild($guildColor.$message, "\0", $priority);
		$event = new AOChatEvent();
		$event->type = "sendguild";
		$event->channel = $this->vars["my_guild"];
		$event->message = $origMsg;
		$event->sender = $this->vars["name"];
		$this->eventManager->fireEvent($event, $disableRelay);

		if ($disableRelay) {
			return;
		}
		$rMessage = new RoutableMessage($origMsg);
		$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$rMessage->prependPath(new Source(
			Source::ORG,
			$this->vars["my_guild"],
			($abbr === 'none') ? null : $abbr
		));
		$this->messageHub->handle($rMessage);
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
		if ( ($this->vars["use_proxy"]??0) == 1
			&& $this->settingManager->getBool('force_mass_tells')
			&& $this->settingManager->getBool('allow_mass_tells')
		) {
			$this->sendMassTell($message, $character, $priority, $formatMessage);
			return;
		}
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendTell($page, $character, $priority, $formatMessage);
			}
			return;
		}

		$priority ??= $this->chatqueue::PRIORITY_MED;

		$rMessage = new RoutableMessage($message);
		$tellColor = "";
		if ($formatMessage) {
			$message = $this->text->formatMessage($message);
			$tellColor = $this->settingManager->getString("default_tell_color")??"";
		}

		$this->logger->logChat("Out. Msg.", $character, $message);
		$this->send_tell($character, $tellColor.$message, "\0", $priority);
		$event = new AOChatEvent();
		$event->type = "sendmsg";
		$event->channel = $character;
		$event->message = $message;
		$this->eventManager->fireEvent($event);
		$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
		$rMessage->prependPath(new Source(Source::TELL, $this->char->name));
		$this->messageHub->handle($rMessage);
	}

	/**
	 * Send a mass message via the chatproxy to another player/bot
	 * @param string|string[] $message
	 */
	public function sendMassTell($message, string $character, int $priority=null, bool $formatMessage=true, int $worker=null): void {
		$priority ??= $this->chatqueue::PRIORITY_HIGH;

		// If we're not using a chat proxy or mass tells are disabled, this doesn't do anything
		if (($this->vars["use_proxy"]??0) == 0
			|| !$this->settingManager->getBool('allow_mass_tells')) {
			$this->sendTell($message, $character, $priority, $formatMessage);
			return;
		}
		$this->numSpamMsgsSent++;
		$message = (array)$message;
		$sendToWorker = $this->proxyCapabilities->supportsSendMode(ProxyCapabilities::SEND_BY_WORKER)
			&& isset($worker)
			&& $this->settingManager->getBool('reply_on_same_worker');
		$sendByMsg = $this->proxyCapabilities->supportsSendMode(ProxyCapabilities::SEND_BY_MSGID)
			&& $this->settingManager->getBool('paging_on_same_worker')
			&& count($message) > 1;
		foreach ($message as $page) {
			$tellColor = "";
			if ($formatMessage) {
				$page = $this->text->formatMessage($page);
				$tellColor = $this->settingManager->getString("default_tell_color")??"";
			}
			if (!$this->proxyCapabilities->supportsSelectors()) {
				$extra = "spam";
			} elseif ($sendToWorker) {
				$extra = json_encode((object)[
					"mode" => ProxyCapabilities::SEND_BY_WORKER,
					"worker" => $worker
					]);
			} elseif ($sendByMsg) {
				$extra = json_encode((object)[
					"mode" => ProxyCapabilities::SEND_BY_MSGID,
					"msgid" => $this->numSpamMsgsSent
				]);
			} else {
				$extra = json_encode((object)[
					"mode" => ProxyCapabilities::SEND_PROXY_DEFAULT,
				]);
			}
			$this->logger->logChat("Out. Msg.", $character, $page);
			$this->send_tell($character, $tellColor.$page, $extra, $priority);
		}
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

		$priority ??= $this->chatqueue::PRIORITY_MED;

		$message = $this->text->formatMessage($origMessage = $message);
		$guildColor = $this->settingManager->getString("default_guild_color")??"";

		$rMessage = new RoutableMessage($origMessage);
		$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
		$rMessage->prependPath(new Source(Source::PUB, $channel));
		$this->messageHub->handle($rMessage);

		$this->send_group($channel, $guildColor.$message, "\0", $priority);
	}

	/**
	 * Returns a command type in the proper format
	 *
	 * @param null|string|string[] $type A space-separate list of any combination of "msg", "priv" and "guild"
	 * @param string|string[] $admin A space-separate list of access rights needed
	 */
	public function processCommandArgs(&$type, &$admin): bool {
		if ($type === null || $type === "") {
			$type = ["msg", "priv", "guild"];
		} elseif (is_string($type)) {
			$type = explode(' ', $type);
		}

		if (!is_string($admin)) {
			throw new InvalidArgumentException("Wrong parameter type 2 to " .__FUNCTION__);
		}

		$admin = explode(' ', $admin);
		if (count($admin) === 1) {
			$admin = array_fill(0, count($type), $admin[0]);
		} elseif (count($admin) != count($type)) {
			$this->logger->error("The number of type arguments does not equal the number of admin arguments for command/subcommand registration");
			return false;
		}
		return true;
	}

	/**
	 * Process an incoming message packet that the bot receives
	 */
	public function process_packet(AOChatPacket $packet): void {
		// $this->logger->notice("< {$packet->type}");
		try {
			$this->process_all_packets($packet);

			// event handlers
			switch ($packet->type) {
				case AOChatPacket::LOGIN_OK: // 5
					$this->buddyListSize += 1000;
					break;
				case AOChatPacket::GROUP_ANNOUNCE: // 60
					$this->processGroupAnnounce(...$packet->args);
					break;
				case AOChatPacket::PRIVGRP_CLIJOIN: // 55, Incoming player joined private chat
					$this->processPrivateChannelJoin(...$packet->args);
					break;
				case AOChatPacket::PRIVGRP_CLIPART: // 56, Incoming player left private chat
					$this->processPrivateChannelLeave(...$packet->args);
					break;
				case AOChatPacket::PRIVGRP_KICK: // 51, we were kicked from private channel
				case AOChatPacket::PRIVGRP_PART: // 53, we left a private channel
					$this->processPrivateChannelKick(...$packet->args);
					break;
				case AOChatPacket::BUDDY_ADD: // 40, Incoming buddy logon or off
					$this->processBuddyUpdate(...$packet->args);
					break;
				case AOChatPacket::BUDDY_REMOVE: // 41, Incoming buddy removed
					$this->processBuddyRemoved(...$packet->args);
					break;
				case AOChatPacket::MSG_PRIVATE: // 30, Incoming Msg
					$this->processPrivateMessage(...$packet->args);
					break;
				case AOChatPacket::PRIVGRP_MESSAGE: // 57, Incoming priv message
					$this->processPrivateChannelMessage(...$packet->args);
					break;
				case AOChatPacket::GROUP_MESSAGE: // 65, Public and guild channels
					$this->processPublicChannelMessage(...$packet->args);
					break;
				case AOChatPacket::PRIVGRP_INVITE: // 50, private channel invite
					$this->processPrivateChannelInvite(...$packet->args);
					break;
				case AOChatPacket::PING: // 100, pong
					$this->processPingReply(...$packet->args);
					break;
			}
		} catch (StopExecutionException $e) {
			$this->logger->info('Execution stopped prematurely', ["exception" => $e]);
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
	 * Handle an incoming AOChatPacket::GROUP_ANNOUNCE packet
	 */
	public function processGroupAnnounce(string $groupId, string $groupName): void {
		$orgId = $this->getOrgId($groupId);
		$this->logger->info("AOChatPacket::GROUP_ANNOUNCE => name: '$groupName'");
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
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}
		$sender = $this->lookup_user($userId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID received: {$userId}");
			return;
		}
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->info("AOChatPacket::PRIVGRP_CLIJOIN => channel: '$channel' sender: '$sender'");

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "joinpriv";

			$this->logger->logChat("Priv Group", -1, "$sender joined the channel.");
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->action = AccessManager::JOIN;
			$this->accessManager->addAudit($audit);

			$this->banController->handleBan(
				$userId,
				function (int $userId, string $sender) use ($eventObj): void {
					// Add sender to the chatlist
					$this->chatlist[$sender] = true;

					$this->eventManager->fireEvent($eventObj);
				},
				// Remove sender if they are banned
				function (int $userId, string $sender): void {
					$this->privategroup_kick($sender);
					$audit = new Audit();
					$audit->actor = $this->char->name;
					$audit->actor = $sender;
					$audit->action = AccessManager::KICK;
					$audit->value = "banned";
					$this->accessManager->addAudit($audit);
				},
				$sender
			);
		} elseif ($this->char->id === $userId) {
			$eventObj->type = "extjoinpriv";

			$this->logger->notice("Joined the private channel {$channel}.");
			$this->privateChats[$channel] = true;
			$pc = new PrivateChannel($channel);
			$this->messageHub
				->registerMessageEmitter($pc)
				->registerMessageReceiver($pc);
			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle a player leaving a private group
	 */
	public function processPrivateChannelLeave(int $channelId, int $userId): void {
		$eventObj = new AOChatEvent();
		$channel = $this->lookup_user($channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}
		$sender = $this->lookup_user($userId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID received: {$userId}");
			return;
		}
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->info("AOChatPacket::PRIVGRP_CLIPART => channel: '$channel' sender: '$sender'");

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "leavepriv";

			$this->logger->logChat("Priv Group", -1, "$sender left the channel.");

			// Remove from Chatlist array
			unset($this->chatlist[$sender]);

			$this->eventManager->fireEvent($eventObj);
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->action = AccessManager::LEAVE;
			$this->accessManager->addAudit($audit);
		} elseif ($this->char->id === $userId) {
			unset($this->privateChats[$channel]);
		} else {
			$eventObj->type = "otherleavepriv";
			$this->eventManager->fireEvent($eventObj);
		}
	}

	/**
	 * Handle bot being kicked from private channel / leaving by itself
	 */
	public function processPrivateChannelKick(int $channelId): void {
		$channel = $this->lookup_user($channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}

		$this->logger->info("AOChatPacket::PRIVGRP_KICK => channel: '$channel'");
		$this->logger->notice("Left the private channel {$channel}.");

		$eventObj = new AOChatEvent();
		$sender = $this->char->name;
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;
		$eventObj->type = "extleavepriv";

		unset($this->privateChats[$channel]);
			$this->messageHub
				->unregisterMessageEmitter(Source::PRIV . "({$channel})")
				->unregisterMessageReceiver(Source::PRIV . "({$channel})");

		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Handle logon/logoff events of friends
	 */
	public function processBuddyUpdate(int $userId, int $status, string $extra): void {
		$sender = $this->lookup_user($userId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid user ID received: {$userId}");
			return;
		}

		$eventObj = new UserStateEvent();
		$eventObj->sender = $sender;

		$this->logger->info("AOChatPacket::BUDDY_ADD => sender: '$sender' status: '$status'");

		$worker = 0;
		try {
			$payload = json_decode($extra, false, 512, JSON_THROW_ON_ERROR);
			$worker = $payload->id ?? 0;
		} catch (Throwable $e) {
		}

		// If this UID was added via the queue, then every UID before its
		// queue entry is an inactive or non-existing player
		$queuePos = array_search($userId, $this->buddyQueue);
		if ($queuePos !== false) {
			$remUid = array_shift($this->buddyQueue);
			while (isset($remUid) && $remUid !== $userId) {
				$this->logger->info("Removing non-existing UID {$remUid} from buddylist");
				$this->buddylistManager->updateRemoved($remUid);
				$remUid = array_shift($this->buddyQueue);
			}
		}
		$this->buddylistManager->update($userId, (bool)$status, $worker);

		// Ignore Logon/Logoff from other bots or phantom logon/offs
		if ($sender === "") {
			return;
		}

		// Status => 0: logoff  1: logon
		$eventObj->type = "logon";
		if ($status === 0) {
			$eventObj->type = "logoff";
			$this->logger->info("$sender logged off");
		} else {
			$this->logger->info("$sender logged on");
		}
		$this->eventManager->fireEvent($eventObj);
	}

	/**
	 * Handle that a friend was removed from the friendlist
	 */
	public function processBuddyRemoved(int $userId): void {
		$sender = $this->lookup_user($userId);

		$this->logger->info("AOChatPacket::BUDDY_REMOVE => sender: '$sender'");

		$this->buddylistManager->updateRemoved($userId);
	}

	/**
	 * Handle an incoming tell
	 */
	public function processPrivateMessage(int $senderId, string $message, string $extra): void {
		$type = "msg";
		$sender = $this->lookup_user($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sener ID received: {$senderId}");
			return;
		}

		$this->logger->info("AOChatPacket::MSG_PRIVATE => sender: '$sender' message: '$message'");

		// Removing tell color
		if (preg_match("/^<font color='#([0-9a-f]+)'>(.+)$/si", $message, $arr)) {
			$message = $arr[2];
		}
		// When we send comands via text->makeChatcmd(), the ' gets escaped
		// and we need to unescape it. But let's be sure by checking that
		// we haven't been passed some actual HTML
		if (strpos($message, '<') === false) {
			$message = str_replace('&#39;', "'", $message);
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->type = $type;
		$eventObj->message = $message;
		if ($extra !== "\0") {
			try {
				$extraData = json_decode($extra, false, 512, JSON_THROW_ON_ERROR);
				if (isset($extraData) && is_object($extraData) && isset($extraData->id)) {
					$eventObj->worker = $extraData->id;
				}
			} catch (Throwable $e) {
			}
		}

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

		$rMsg = new RoutableMessage($message);
		$rMsg->appendPath(new Source(Source::TELL, $sender));
		$rMsg->setCharacter(new Character($sender, $senderId, (int)$this->vars['dimension']));
		if ($this->messageHub->handle($rMsg) !== $this->messageHub::EVENT_NOT_ROUTED) {
			return;
		}

		$this->banController->handleBan(
			$senderId,
			function(int $senderId, AOChatEvent $eventObj, string $message, string $sender, string $type): void {
				$this->eventManager->fireEvent($eventObj);

				// remove the symbol if there is one
				if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
					$message = substr($message, 1);
				}

				// check tell limits
				$context = new CmdContext($sender, $senderId);
				$context->channel = $type;
				$context->message = $message;
				$context->sendto = new PrivateMessageCommandReply($this, $sender, $eventObj->worker ?? null);
				$this->limitsController->checkAndExecute(
					$sender,
					$message,
					function(CmdContext $context): void {
						$this->commandManager->processCmd($context);
					},
					$context
				);
			},
			null,
			$eventObj,
			$message,
			$sender,
			$type
		);
	}

	/**
	 * Handle a message on a private channel
	 */
	public function processPrivateChannelMessage(int $channelId, int $senderId, string $message): void {
		$channel = $this->lookup_user($channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}
		$sender = $this->lookup_user($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID received: {$senderId}");
			return;
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->info("AOChatPacket::PRIVGRP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");
		$this->logger->logChat($channel, $sender, $message);

		if ($sender == $this->vars["name"]) {
			return;
		}
		if ($this->isDefaultPrivateChannel($channel)) {
			$type = "priv";
		} else {  // ext priv group message
			$type = "extpriv";
		}
		$eventObj->type = $type;
		$this->eventManager->fireEvent($eventObj);
		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(new Character($sender, $senderId));
		$label = null;
		if (isset($this->vars["my_guild"]) && strlen($this->vars["my_guild"])) {
			$label = "Guest";
		}
		$rMessage->prependPath(new Source(Source::PRIV, $channel, $label));
		$this->messageHub->handle($rMessage);
		if ($message[0] !== $this->settingManager->get("symbol")
			|| strlen($message) <= 1
			|| !$this->isDefaultPrivateChannel($channel)
		) {
			return;
		}

		$context = new CmdContext($sender, $senderId);
		$context->channel = $type;
		$context->message = substr($message, 1);
		$context->sendto = new PrivateChannelCommandReply($this, $channel);
		$this->banController->handleBan(
			$senderId,
			function (int $senderId, CmdContext $context): void {
				$this->commandManager->processCmd($context);
			},
			null,
			$context
		);
	}

	/**
	 * Handle a message on a public channel
	 */
	public function processPublicChannelMessage(string $channelId, int $senderId, string $message): void {
		$channel = $this->get_gname($channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}
		$sender = $this->lookup_user($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID received: {$senderId}");
			return;
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->info("AOChatPacket::GROUP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");

		$orgId = $this->getOrgId($channelId);

		// Route public messages not from the bot itself
		if ($sender !== $this->vars["name"]) {
			if (!$orgId || $this->settingManager->getBool('guild_channel_status')) {
				$rMessage = new RoutableMessage($message);
				if ($this->util->isValidSender($sender)) {
					$rMessage->setCharacter(new Character($sender, $senderId));
				}
				if ($orgId) {
					$abbr = $this->settingManager->getString('relay_guild_abbreviation');
					$rMessage->prependPath(new Source(
						Source::ORG,
						$channel,
						($abbr === 'none') ? null : $abbr
					));
				} else {
					$rMessage->prependPath(new Source(Source::PUB, $channel));
				}
				$this->messageHub->handle($rMessage);
			}
		}
		if (in_array($channel, $this->channelsToIgnore)) {
			return;
		}

		// don't log tower messages with rest of chat messages
		if ($channel != "All Towers" && $channel != "Tower Battle Outcome" && (!$orgId || $this->settingManager->getBool('guild_channel_status'))) {
			$this->logger->logChat($channel, $sender, $message);
		} else {
			$this->logger->info("[" . $channel . "]: " . $message);
		}

		if ($this->util->isValidSender($sender)) {
			// ignore messages that are sent from the bot self
			if ($sender == $this->vars["name"]) {
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

			$eventObj->type = $type;

			$this->eventManager->fireEvent($eventObj);

			if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
				$context = new CmdContext($sender, $senderId);
				$context->channel = "guild";
				$context->message = substr($message, 1);
				$context->sendto = new GuildChannelCommandReply($this);
				$this->banController->handleBan(
					$senderId,
					function (int $senderId, CmdContext $context): void {
						$this->commandManager->processCmd($context);
					},
					null,
					$context
				);
			}
		}
	}

	/**
	 * Handle an invite to a private channel
	 */
	public function processPrivateChannelInvite(int $channelId): void {
		$type = "extjoinprivrequest"; // Set message type.
		$sender = $this->lookup_user($channelId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->type = $type;

		$this->logger->info("AOChatPacket::PRIVGRP_INVITE => sender: '$sender'");

		$this->logger->logChat("Priv Channel Invitation", -1, "$sender channel invited.");

		$this->eventManager->fireEvent($eventObj);
	}

	public function processPingReply(string $reply): void {
		$classMapping = [
			ProxyCapabilities::CMD_CAPABILITIES => ProxyCapabilities::class,
			ProxyCapabilities::CMD_PING => PingReply::class,
		];
		if ($reply === static::PING_IDENTIFIER) {
			return;
		}
		try {
			$obj = json_decode($reply, false, 512, JSON_THROW_ON_ERROR);
			if (!is_object($obj) || !isset($obj->type) || !isset($classMapping[$obj->type])) {
				throw new Exception();
			}
			/** @var ProxyReply $obj */
			$obj = JsonImporter::convert($classMapping[$obj->type], $obj);
			$this->processProxyReply($obj);
		} catch (Throwable $e) {
			// If we are either not a json pong or no proper reply, we are still a pong
			// Could be no proxy or proxy not supporting the command
			$this->eventManager->fireEvent(new PongEvent(0));
		}
	}

	/** Handle a proxy command reply */
	public function processProxyReply(ProxyReply $reply): void {
		switch ($reply->type) {
			case ProxyCapabilities::CMD_CAPABILITIES:
				if ($reply instanceof ProxyCapabilities) {
					$this->processProxyCapabilities($reply);
				}
				return;
			case ProxyCapabilities::CMD_PING:
				if ($reply instanceof PingReply) {
					$this->processWorkerPong($reply);
				}
				return;
		}
	}

	/** A worker did a ping for us */
	public function processWorkerPong(PingReply $reply): void {
		$this->eventManager->fireEvent(new PongEvent($reply->worker));
	}

	/** Send a query to the proxy and ask for its supported capabilities */
	public function queryProxyFeatures(): void {
		$this->sendPing(json_encode((object)["cmd" => ProxyCapabilities::CMD_CAPABILITIES]));
	}

	/** Proxy send us capabilities information */
	public function processProxyCapabilities(ProxyCapabilities $reply): void {
		$this->proxyCapabilities = $reply;
		if ($reply->rate_limited && isset($this->chatqueue)) {
			$this->chatqueue->disable();
		}
	}

	/**
	 * Retrieve the character name of a UID, or null if inactive or UID doesn't exist
	 *
	 * @param mixed $args
	 * @psalm-param callable(?string, mixed...) $callback
	 */
	public function getName(int $uid, callable $callback, ...$args): void {
		$dummyName = "_" . (string)(microtime(true)*10000);
		unset($this->id[$dummyName]);
		if (isset($this->id[$uid])) {
			$callback((string)$this->id[$uid], ...$args);
			return;
		}
		$buddyEntry = $this->buddylistManager->buddyList[$uid] ?? null;
		if (isset($buddyEntry)) {
			if ($buddyEntry->known) {
				$callback(null, ...$args);
				return;
			}
		} else {
			$this->buddylistManager->addId($uid, "name_lookup");
		}
		$this->getUid($dummyName, function(?int $null) use ($dummyName, $uid, $callback, $args): void {
			unset($this->id[$dummyName]);
			$this->buddylistManager->removeId($uid, "name_lookup");
			$name = $this->id[(int)$uid] ?? null;
			if (!is_string($name) || $name === '4294967295') {
				$name = null;
			}
			$callback($name, ...$args);
		});
	}

	/**
	 * Send a ping packet to keep the connection open
	 */
	public function sendPing(string $payload=null): bool {
		if (!isset($payload)) {
			$payload = static::PING_IDENTIFIER;
		}
		$this->last_ping = time();
		return $this->sendPacket(new AOChatPacket("out", AOChatPacket::PING, $payload));
	}

	/**
	 * Send a ping packet via a worker to keep the connection open
	 */
	public function sendPingViaWorker(int $worker, string $payload): bool {
		return $this->sendPing(
			json_encode(
				(object)[
					"cmd" => ProxyCapabilities::CMD_PING,
					"worker" => $worker,
					"payload" => $payload,
				]
			)
		);
	}

	public function registerEvents(string $class): void {
		$reflection = new ReflectionClass($class);

		foreach ($reflection->getAttributes(NCA\ProvidesEvent::class) as $eventAttr) {
			/** @var NCA\ProvidesEvent */
			$eventObj = $eventAttr->newInstance();
			$this->eventManager->addEventType($eventObj->value, $eventObj->desc);
		}
	}

	public function registerSettingHandlers(string $class): void {
		if (!is_subclass_of($class, CoreSettingHandler::class)) {
			return;
		}
		$reflection = new ReflectionClass($class);

		foreach ($reflection->getAttributes(NCA\SettingHandler::class) as $settingAttr) {
			/** @var NCA\SettingHandler */
			$AttrObj = $settingAttr->newInstance();
			$this->settingManager->registerSettingHandler($AttrObj->value, $class);
		}
	}

	/**
	 * Register a module
	 *
	 * In order to later easily find a module, it registers here
	 * and other modules can get the instance by querying for $name
	 */
	public function registerInstance(string $name, object $obj): void {
		$this->logger->info("Registering instance name '$name' for module '{$obj->moduleName}'");
		$moduleName = $obj->moduleName;

		// register settings annotated on the class
		$reflection = new ReflectionClass($obj);

		// register commands, subcommands, and events annotated on the class
		$commands = [];
		$subcommands = [];
		foreach ($reflection->getAttributes(NCA\DefineCommand::class) as $attribute) {
			/** @var AttributesDefineCommand */
			$attribute = $attribute->newInstance();
			$command = $attribute->command;
			$definition = [
				'channels'      => $attribute->channels,
				'defaultStatus' => $attribute->defaultStatus,
				'accessLevel'   => $attribute->accessLevel??"mod",
				'description'   => $attribute->description,
				'help'          => $attribute->help,
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
			if ($attribute->alias) {
				$this->commandAlias->register($moduleName, $command, $attribute->alias);
			}
		}

		foreach ($reflection->getMethods() as $method) {
			if (count($method->getAttributes(NCA\Setup::class))) {
				if (call_user_func([$obj, $method->name]) === false) {
					$this->logger->error("Failed to call setup handler for '$name'");
				}
			}
			foreach ($method->getAttributes(NCA\HandlesCommand::class) as $command) {
				/** @var NCA\HandlesCommand */
				$command = $command->newInstance();
				$commandName = $command->value;
				$handlerName = "{$name}.{$method->name}";
				if (isset($commands[$commandName])) {
					$commands[$commandName]['handlers'][] = $handlerName;
				} elseif (isset($subcommands[$commandName])) {
					$subcommands[$commandName]['handlers'][] = $handlerName;
				} else {
					$this->logger->warning("Cannot handle command '$commandName' as it is not defined with #[DefineCommand] in '$name'.");
				}
			}
			foreach ($method->getAttributes(NCA\Event::class) as $eventAnnotation) {
				/** @var NCA\Event */
				$event = $eventAnnotation->newInstance();
				foreach ((array)$event->name as $eventName) {
					$this->eventManager->register(
						$moduleName,
						$eventName,
						$name . '.' . $method->name,
						$event->description,
						$event->help,
						$event->defaultStatus
					);
				}
			}
		}

		foreach ($commands as $command => $definition) {
			if (count($definition['handlers']) === 0) {
				$this->logger->error("No handlers defined for command '$command' in module '$moduleName'.");
				continue;
			}
			$this->commandManager->register(
				$moduleName,
				$definition['channels'],
				implode(',', $definition['handlers']),
				(string)$command,
				$definition['accessLevel'],
				$definition['description']??"No description given",
				$definition['help'],
				$definition['defaultStatus']
			);
		}

		foreach ($subcommands as $subcommand => $definition) {
			if (count($definition['handlers']) == 0) {
				$this->logger->error("No handlers defined for subcommand '$subcommand' in module '$moduleName'.");
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
		$reflection = new ReflectionClass($obj);
		foreach ($reflection->getMethods() as $method) {
			if (empty($method->getAttributes(NCA\Setup::class))) {
				continue;
			}
			if (call_user_func([$obj, $method->name]) === false) {
				$this->logger->error("Failed to call setup handler for '$name'");
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

	/**
	 * Lookup the username of a user id
	 */
	public function lookupID(int $id): ?string {
		if (isset($this->id[$id])) {
			return (string)$this->id[$id];
		}

		$buddyPayload = json_encode(["mode" => ProxyCapabilities::SEND_BY_WORKER, "worker" => 0]);
		$removeFromBuddylist = !isset($this->buddylistManager->buddyList[$id]);
		$this->buddy_add($id, $buddyPayload);
		// Adding a non-existing uid as a buddy will never give any reply back.
		// Because Funcom guarantees that the order of packet-replies is the same as the requests,
		// we know that as soon as we have the reply to a (always succeeding) user lookup,
		// the buddy packet must have arrived already. If not, the UID was deleted
		unset($this->id["0"]);
		$this->lookup_user("0");
		if ($removeFromBuddylist) {
			$this->buddylistManager->removeId($id);
		}

		if (isset($this->id[$id])) {
			return (string)$this->id[$id];
		}
		return null;
	}

	public function getPacket(): ?AOChatPacket {
		$result = parent::getPacket();
		if (!isset($result) || $result->type !== AOChatPacket::GROUP_ANNOUNCE) {
			return $result;
		}
		$data = unpack("Ctype/Nid", (string)$result->args[0]);
		if ($data["type"] !== 3) { // guild channel
			$pc = new PublicChannel($result->args[1]);
			Registry::injectDependencies($pc);
			$this->messageHub->registerMessageEmitter($pc);
			if (in_array($data["type"], [135])) {
				$this->messageHub->registerMessageReceiver($pc);
			}
		} else {
			$oc = new OrgChannel();
			Registry::injectDependencies($oc);
			$this->messageHub
				->registerMessageEmitter($oc)
				->registerMessageReceiver($oc);
		}
		return $result;
	}
}
