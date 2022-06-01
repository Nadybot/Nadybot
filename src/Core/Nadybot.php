<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\call;
use function Amp\Promise\all;
use function Safe\json_encode;

use Amp\Coroutine;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Exception;
use Generator;
use Throwable;
use Nadybot\Core\{
	Attributes as NCA,
	Channels\OrgChannel,
	Channels\PrivateChannel,
	Channels\PublicChannel,
	Channels\PrivateMessage,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SettingHandler as CoreSettingHandler,
	Modules\BAN\BanController,
	Modules\LIMITS\LimitsController,
};
use Nadybot\Core\DBSchema\{
	Audit,
	CmdCfg,
	EventCfg,
	HlpCfg,
	Setting,
};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

#[NCA\Instance]
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
	public Timer $timer;

	#[NCA\Inject]
	public LimitsController $limitsController;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public ConfigFile $config;

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

	/** @var array<string,bool> */
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

	/** Time the bot was started */
	public int $startup;

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
	 */
	public function init(BotRunner $runner): void {
		$this->started = time();
		$this->runner = $runner;
		$this->proxyCapabilities = new ProxyCapabilities();

		// Set startup time
		$this->startup = time();

		$this->logger->info('Initializing bot');

		// Prepare command/event settings table
		$this->db->table(CommandManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(EventManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(SettingManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(HelpManager::DB_TABLE)->update(["verify" => 0]);
		$this->db->table(EventManager::DB_TABLE)->where("type", "setup")->update(["verify" => 1]);

		// To reduce queries load core items into memory
		$this->db->table(CommandManager::DB_TABLE)
			->where("cmdevent", "subcmd")
			->asObj(CmdCfg::class)
			->each(function(CmdCfg $row): void {
				$this->existing_subcmds[$row->cmd] = true;
			});

		$this->db->table(EventManager::DB_TABLE)->asObj(EventCfg::class)
			->each(function(EventCfg $row): void {
				$this->existing_events[$row->type??""][$row->file??""] = true;
			});

		$this->db->table(HelpManager::DB_TABLE)->asObj(HlpCfg::class)
			->each(function(HlpCfg $row): void {
				$this->existing_helps[$row->name] = true;
			});

		$this->existing_settings = [];
		$this->db->table(SettingManager::DB_TABLE)->asObj(Setting::class)
			->each(function(Setting $row): void {
				$this->existing_settings[$row->name] = true;
			});

		$this->db->beginTransaction();
		$allClasses = get_declared_classes();
		foreach ($allClasses as $class) {
			$this->registerEvents($class);
			$this->registerSettingHandlers($class);
		}
		$this->db->commit();
		Loop::run(function () {
			$procs = [];
			$this->db->beginTransaction();
			foreach (Registry::getAllInstances() as $name => $instance) {
				if ($instance instanceof ModuleInstanceInterface && $instance->getModuleName() !== "") {
					$procs []= $this->registerInstance($name, $instance);
				} else {
					$procs []= $this->callSetupMethod($name, $instance);
				}
				if (!$this->db->inTransaction()) {
					$this->db->beginTransaction();
				}
			}
			yield all($procs);
			Loop::stop();
		});
		$this->db->commit();
		$this->settingManager::$isInitialized = true;

		//Delete old entries in the DB
		$this->db->table(CommandManager::DB_TABLE)->where("verify", 0)
			->asObj(CmdCfg::class)
			->each(function(CmdCfg $row): void {
				$this->logger->notice(
					"Deleting removed command '{command}' from module {module}",
					[
						"command" => $row->cmd,
						"module" => $row->module,
					]
				);
			});
		$this->db->table(CommandManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(EventManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(SettingManager::DB_TABLE)->where("verify", 0)
			->asObj(Setting::class)
			->each(function(Setting $row): void {
				$this->logger->notice(
					"Deleting removed setting '{setting}' from module {module}",
					[
						"setting" => $row->name,
						"module" => $row->module,
					]
				);
			});
		$this->db->table(HelpManager::DB_TABLE)->where("verify", 0)->delete();
		$this->db->table(SettingManager::DB_TABLE)->where("verify", 0)->delete();

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
		$this->logger->notice("Connecting to {type} {server}:{port}", [
			"type" => $this->config->useProxy ? "AO Chat Proxy" : "AO Server",
			"server" => $server,
			"port" => $port,
		]);
		$try = 1;
		while (!$this->connect($server, $port, $try === 1)) {
			if ($this->config->useProxy) {
				$this->logger->notice("Waiting for proxy to be available...");
				usleep(250000);
				$try++;
			} else {
				$this->logger->critical("Connection failed! Please check your Internet connection and firewall.");
				\Safe\sleep(10);
				die();
			}
		}

		$this->logger->notice("Authenticate login data...");
		if (null === $this->authenticate($login, $password)) {
			$this->logger->critical("Login failed.");
			\Safe\sleep(10);
			exit(1);
		}

		$this->logger->notice("Logging in {$this->config->name}...");
		if (false === $this->login($this->config->name)) {
			$this->logger->critical("Character selection failed.");
			\Safe\sleep(10);
			exit(1);
		}
		if (!isset($this->socket)) {
			die();
		}

		if (socket_set_nonblock($this->socket)) {
			$this->logger->notice("Connection with AO switched to non-blocking");
		} else {
			$this->logger->warning("Unable to switch the AO-connection to non-blocking");
		}
		if ($this->config->useProxy) {
			$this->queryProxyFeatures();
		}

		$this->buddyListSize += 1000;
		$this->logger->notice("Successfully logged in", [
			"name" => $this->config->name,
			"login" => $login,
			"server" => $server,
			"port" => $port,
		]);
		$pc = new PrivateChannel($this->config->name);
		Registry::injectDependencies($pc);
		$this->messageHub
			->registerMessageReceiver($pc)
			->registerMessageEmitter($pc);

		$pm = new PrivateMessage();
		Registry::injectDependencies($pm);
		$this->messageHub
			->registerMessageReceiver($pm)
			->registerMessageEmitter($pm);
		$this->commandManager->registerSource(Source::PRIV . "(*)");
		$this->commandManager->registerSource(Source::ORG);
		$this->commandManager->registerSource(Source::TELL . "(*)");
	}

	/**
	 * The main endless-loop of the bot
	 */
	public function run(): void {
		Loop::run(function() {
			Loop::setErrorHandler(function(?Throwable $e): void {
				if (isset($e)) {
					$this->logger->error($e->getMessage(), ["exception" => $e]);
				}
			});
			$loop = new EventLoop();
			Registry::injectDependencies($loop);
			Loop::defer([$loop, "execSingleLoop"]);

			$signalHandler = function (): void {
				$this->logger->notice('Shutdown requested.');
				Loop::stop();
			};
			if (function_exists('sapi_windows_set_ctrl_handler')) {
				\Safe\sapi_windows_set_ctrl_handler($signalHandler, true);
			} else {
				Loop::onSignal(SIGTERM, $signalHandler);
				Loop::onSignal(SIGINT, $signalHandler);
			}

			Loop::repeat(1000, [$this->eventManager, "crons"]);
			Loop::repeat(1000, function() {
				if ($this->ready) {
					$this->timer->executeTimerEvents();
				}
			});
		});
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
		$unreadyWait = $this->config->useProxy ? 2 : 1;
		$packet = $this->waitForPacket($this->isReady() ? 0 : $unreadyWait);
		if ($packet) {
			$this->process_packet($packet);
			return true;
		}
		if (!strlen($this->readBuffer) && !strlen($this->writeBuffer)) {
			if ($this->ready === false) {
				Loop::defer([$this->eventManager, "executeConnectEvents"]);
			}
			$this->ready = true;
			return false;
		}
		return true;
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
			$group = $this->char->name;
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
		$event->sender = $this->config->name;
		$this->eventManager->fireEvent($event, $disableRelay);
		if (!$disableRelay) {
			$rMessage = new RoutableMessage($origMsg);
			$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
			$label = null;
			if (strlen($this->config->orgName)) {
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
		$event->channel = $this->config->orgName;
		$event->message = $origMsg;
		$event->sender = $this->config->name;
		$this->eventManager->fireEvent($event, $disableRelay);

		if ($disableRelay) {
			return;
		}
		$rMessage = new RoutableMessage($origMsg);
		$rMessage->setCharacter(new Character($this->char->name, $this->char->id));
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$rMessage->prependPath(new Source(
			Source::ORG,
			$this->config->orgName,
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
		if ($this->config->useProxy
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
		if (!$this->config->useProxy
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
			$this->config->orgId = $orgId;
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
		$this->setUserState($userId, $sender, true);
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
		$this->setUserState($userId, $sender, true);
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

	public function setUserState(int $userId, string $charName, bool $online=true): void {
		if ($online === false || $userId === $this->char->id) {
			return;
		}
		$this->logger->info("Register user {name} (ID {id}) as online", [
			"name" => $charName,
			"id" => $userId,
		]);
		$this->db->table("last_online")
			->upsert(
				[
					"uid" => $userId,
					"name" => $charName,
					"dt" => time(),
				],
				["uid"],
			);
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
		$this->setUserState($userId, $sender, $status === 1);

		$eventObj = new UserStateEvent();
		$eventObj->uid = $userId;
		$eventObj->sender = $sender;

		$this->logger->info("AOChatPacket::BUDDY_ADD => sender: '$sender' status: '$status'");

		$worker = 0;
		try {
			$payload = \Safe\json_decode($extra);
			$worker = $payload->id ?? 0;
		} catch (Throwable $e) {
		}

		// If this UID was added via the queue, then every UID before its
		// queue entry is an inactive or non-existing player
		$queuePos = array_search($userId, $this->buddyQueue);
		if (!$this->config->useProxy && $queuePos !== false) {
			$remUid = array_shift($this->buddyQueue);
			while (isset($remUid) && $remUid !== $userId) {
				$this->logger->info("Removing non-existing UID {$remUid} from buddylist");
				$this->buddylistManager->updateRemoved($remUid);
				$remUid = array_shift($this->buddyQueue);
			}
		}
		$inRebalance = $this->buddylistManager->isRebalancing($userId);
		$this->buddylistManager->update($userId, (bool)$status, $worker);

		// Ignore Logon/Logoff from other bots or phantom logon/offs
		if ($inRebalance || $sender === "") {
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
		$this->setUserState($senderId, $sender, true);

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
				$extraData = \Safe\json_decode($extra);
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
		} elseif (preg_match("|Unknown command '|si", $message)) {
			return;
		} elseif (preg_match("|Use .autoinvite to control your auto|si", $message)) {
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
		$rMsg->setCharacter(new Character($sender, $senderId, $this->config->dimension));
		if ($this->messageHub->handle($rMsg) !== $this->messageHub::EVENT_NOT_ROUTED) {
			return;
		}

		$this->banController->handleBan(
			$senderId,
			function(int $senderId, AOChatEvent $eventObj, string $message, string $sender, string $type): void {
				$this->eventManager->fireEvent($eventObj);

				$context = new CmdContext($sender, $senderId);
				$context->message = $message;
				$context->source = Source::TELL . "({$sender})";
				$context->sendto = new PrivateMessageCommandReply($this, $sender, $eventObj->worker ?? null);
				$context->setIsDM();
				$this->limitsController->checkAndExecute(
					$sender,
					$message,
					function(CmdContext $context): void {
						$this->commandManager->checkAndHandleCmd($context);
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
		$this->setUserState($senderId, $sender, true);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->info("AOChatPacket::PRIVGRP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");
		$this->logger->logChat($channel, $sender, $message);

		if ($sender == $this->config->name) {
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
		if (strlen($this->config->orgName)) {
			$label = "Guest";
		}
		$rMessage->prependPath(new Source(Source::PRIV, $channel, $label));
		$this->messageHub->handle($rMessage);
		$context = new CmdContext($sender, $senderId);
		$context->message = $message;
		$context->source = Source::PRIV . "({$channel})";
		$context->sendto = new PrivateChannelCommandReply($this, $channel);
		$this->commandManager->checkAndHandleCmd($context);
	}

	/**
	 * Handle a message on a public channel
	 */
	public function processPublicChannelMessage(string $channelId, int $senderId, string $message): void {
		$channel = $this->get_gname($channelId);
		if (!isset($channel)) {
			$this->logger->info("Invalid channel ID received: {$channelId}");
			return;
		}
		$sender = $this->lookup_user($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID received: {$senderId}");
			return;
		}
		$this->setUserState($senderId, $sender, true);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $message;

		$this->logger->info("AOChatPacket::GROUP_MESSAGE => sender: '$sender' channel: '$channel' message: '$message'");

		$orgId = $this->getOrgId($channelId);

		// Route public messages not from the bot itself
		if ($sender !== $this->config->name) {
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
			if ($sender == $this->config->name) {
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
			$context = new CmdContext($sender, $senderId);
			$context->source = Source::ORG;
			$context->message = $message;
			$context->sendto = new GuildChannelCommandReply($this);
			$this->commandManager->checkAndHandleCmd($context);
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
			$obj = \Safe\json_decode($reply);
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
			$name = $this->id[$uid] ?? null;
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

	/** @phpstan-param class-string $class */
	public function registerEvents(string $class): void {
		$reflection = new ReflectionClass($class);

		foreach ($reflection->getAttributes(NCA\ProvidesEvent::class) as $eventAttr) {
			/** @var NCA\ProvidesEvent */
			$eventObj = $eventAttr->newInstance();
			$this->eventManager->addEventType($eventObj->event, $eventObj->desc);
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
			$this->settingManager->registerSettingHandler($AttrObj->name, $class);
		}
	}

	/**
	 * Register a module
	 *
	 * In order to later easily find a module, it registers here
	 * and other modules can get the instance by querying for $name
	 *
	 * @return Promise<void>
	 */
	public function registerInstance(string $name, ModuleInstanceInterface $obj): Promise {
		return call(function () use ($name, $obj): Generator {
			$moduleName = $obj->getModuleName();
			$this->logger->info("Registering instance name '{name}' for module '{moduleName}'", [
				"name" => $name,
				"moduleName" => $moduleName,
			]);

			// register settings annotated on the class
			$reflection = new ReflectionClass($obj);

			[$commands, $subcommands] = $this->parseInstanceCommands($moduleName, $obj);
			$this->parseInstanceSettings($moduleName, $obj);

			foreach ($reflection->getMethods() as $method) {
				if (count($method->getAttributes(NCA\Setup::class))) {
					$result = $method->invoke($obj);
					if ($result instanceof Generator) {
						yield from $result;
					} elseif ($result === false) {
						$this->logger->error("Failed to call setup handler for '$name'");
					}
				}
				foreach ($method->getAttributes(NCA\HandlesCommand::class) as $command) {
					/** @var NCA\HandlesCommand */
					$command = $command->newInstance();
					$commandName = $command->command;
					$handlerName = "{$name}.{$method->name}:".$method->getStartLine();
					if (isset($commands[$commandName])) {
						$commands[$commandName]->handlers []= $handlerName;
					} elseif (isset($subcommands[$commandName])) {
						$subcommands[$commandName]->handlers []= $handlerName;
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
				foreach ($method->getAttributes(NCA\SettingChangeHandler::class) as $changeAnnotation) {
					/** @var NCA\SettingChangeHandler */
					$change = $changeAnnotation->newInstance();
					$closure = $method->getClosure($obj);
					if (!isset($closure)) {
						continue;
					}
					$this->settingManager->registerChangeListener($change->setting, $closure);
				}
			}

			foreach ($commands as $command => $definition) {
				if (count($definition->handlers) === 0) {
					$this->logger->error("No handlers defined for command '$command' in module '$moduleName'.");
					continue;
				}
				$this->commandManager->register(
					$moduleName,
					implode(',', $definition->handlers),
					(string)$command,
					$definition->accessLevel,
					$definition->description,
					$definition->defaultStatus,
				);
			}

			foreach ($subcommands as $subcommand => $definition) {
				if (count($definition->handlers) == 0) {
					$this->logger->error("No handlers defined for subcommand '$subcommand' in module '$moduleName'.");
					continue;
				}
				if (!isset($definition->parentCommand)) {
					continue;
				}
				$this->subcommandManager->register(
					$moduleName,
					implode(',', $definition->handlers),
					$subcommand,
					$definition->accessLevel,
					$definition->parentCommand,
					$definition->description,
					$definition->defaultStatus,
				);
			}
		});
	}

	/**
	 * Parse all defined commands of the class and return them
	 *
	 * @param string $moduleName
	 * @param ModuleInstanceInterface $obj
	 * @return array<array<string,CmdDef>>
	 * @phpstan-return array{array<string,CmdDef>,array<string,CmdDef>}
	 */
	private function parseInstanceCommands(string $moduleName, ModuleInstanceInterface $obj): array {
		/**
		 * register commands, subcommands, and events annotated on the class
		 * @var array<string,CmdDef>
		 */
		$commands = [];
		/** @var array<string,CmdDef> */
		$subcommands = [];
		$reflection = new ReflectionClass($obj);
		foreach ($reflection->getAttributes(NCA\DefineCommand::class) as $attribute) {
			/** @var NCA\DefineCommand */
			$attribute = $attribute->newInstance();
			$command = $attribute->command;
			$definition = new CmdDef(
				defaultStatus: $attribute->defaultStatus,
				accessLevel: $attribute->accessLevel??"mod",
				description: $attribute->description,
				help: $attribute->help,
			);
			[$parentCommand, $subCommand] = explode(" ", $command . " ", 2);
			if ($subCommand !== "") {
				$definition->parentCommand = $parentCommand;
				$subcommands[$command] = $definition;
			} else {
				$commands[$command] = $definition;
			}
			// register command alias if defined
			if (isset($attribute->alias)) {
				foreach ((array)$attribute->alias as $alias) {
					$this->commandAlias->register($moduleName, $command, $alias);
				}
			}
		}
		return [$commands, $subcommands];
	}

	private function parseInstanceSettings(string $moduleName, ModuleInstanceInterface $obj): void {
		$reflection = new ReflectionClass($obj);
		foreach ($reflection->getProperties() as $property) {
			$attrs = $property->getAttributes(NCA\DefineSetting::class, ReflectionAttribute::IS_INSTANCEOF);
			if (empty($attrs)) {
				continue;
			}
			/** @var NCA\DefineSetting */
			$attribute = $attrs[0]->newInstance();
			$attribute->name ??= strtolower(
				preg_replace(
					"/([A-Z][a-z])/",
					'_$1',
					preg_replace(
						"/([A-Z]{2,})(?=[A-Z][a-z]|$)/",
						'_$1',
						preg_replace(
							"/(\d+)$/",
							'_$1',
							$property->getName()
						)
					)
				)
			);

			$type = $property->getType();
			if ($type === null) {
				throw new Exception(
					"Cannot bind untyped property ".
					$property->getDeclaringClass()->getName() . '::$' . $property->getName().
					" to {$attribute->name}."
				);
			}
			if (!($type instanceof ReflectionNamedType)) {
				throw new Exception(
					"Invalid data type of ".
					$property->getDeclaringClass()->getName() . '::$' . $property->getName().
					" for {$attribute->name} setting."
				);
			}
			if (!$property->isInitialized($obj)) {
				throw new Exception(
					"Trying to bind setting {$attribute->name} to uninitialized ".
					"variable " . $property->getDeclaringClass()->getName().
					'::$' . $property->getName()
				);
			}
			$attribute->defaultValue = $property->getValue($obj);
			$comment = $property->getDocComment();
			if ($comment === false) {
				throw new Exception("Missing description for setting {$attribute->name}");
			}
			$comment = trim(preg_replace("|^/\*\*(.*)\*/|s", '$1', $comment));
			$comment = preg_replace("/^[ \t]*\*[ \t]*/m", '', $comment);
			$description = trim(preg_replace("/^@.*/m", '', $comment));
			$this->settingManager->add(
				module: $moduleName,
				name: $attribute->name,
				description: $description,
				mode: $attribute->mode,
				type: $attribute->type,
				value: $attribute->getValue(),
				options: $attribute->options,
				accessLevel: $attribute->accessLevel,
				help: $attribute->help,
			);
			$this->updateTypedProperty($obj, $property, $this->settingManager->settings[$attribute->name]->value);
			$this->eventManager->subscribe(
				"setting({$attribute->name})",
				function (SettingEvent $e) use ($obj, $property): void {
					$this->updateTypedProperty($obj, $property, $e->newValue->value);
				}
			);
		}
	}

	/** Update the property bound to a setting to $value */
	private function updateTypedProperty(ModuleInstanceInterface $obj, ReflectionProperty $property, mixed $value): void {
		$type = $property->getType();
		if ($type === null || !($type instanceof ReflectionNamedType)) {
			return;
		}

		switch ($type->getName()) {
			case 'int':
				$property->setValue($obj, (int)$value);
				return;
			case 'float':
				$property->setValue($obj, (float)$value);
				return;
			case 'bool':
				$property->setValue($obj, (bool)$value);
				return;
			case 'string':
				$property->setValue($obj, (string)$value);
				return;
			default:
				throw new Exception(
					"Invalid type " . $type->getName() . " for ".
					$property->getDeclaringClass()->getName() . '::$' . $property->getName().
					" - cannot be bound to setting."
				);
		}
	}

	/**
	 * Call the setup method for an object
	 * @return Promise<mixed>
	 */
	public function callSetupMethod(string $name, object $obj): Promise {
		$reflection = new ReflectionClass($obj);
		foreach ($reflection->getMethods() as $method) {
			if (empty($method->getAttributes(NCA\Setup::class))) {
				continue;
			}
			$result = $method->invoke($obj);
			if ($result instanceof Generator) {
				return new Coroutine($result);
			}
			if ($result === false) {
				$this->logger->error("Failed to call setup handler for '$name'");
			}
		}
		return new Success();
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
		$b = \Safe\unpack("Ctype/Nid", $channelId);
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
		return $channel === $this->char->name;
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

	/**
	 * Lookup the username of a user id
	 * @return Promise<?string>
	 */
	public function uidToName(int $id): Promise {
		return call(function () use ($id): Generator {
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
			$null = yield $this->getUid2("0");
			if ($removeFromBuddylist) {
				$this->buddylistManager->removeId($id);
			}

			if (isset($this->id[$id])) {
				return (string)$this->id[$id];
			}
			return null;
		});
	}

	public function getPacket(bool $blocking=false): ?AOChatPacket {
		$result = parent::getPacket($blocking);
		if (!isset($result) || $result->type !== AOChatPacket::GROUP_ANNOUNCE) {
			return $result;
		}
		$data = \Safe\unpack("Ctype/Nid", (string)$result->args[0]);
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
