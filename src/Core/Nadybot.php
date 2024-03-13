<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\async;
use function Safe\{preg_match, sapi_windows_set_ctrl_handler, unpack};
use Amp\Http\Client\HttpClientBuilder;
use AO\Client\{Multi, WorkerConfig, WorkerPackage};
use AO\{Group, Package, Utils};
use Exception;
use Nadybot\Core\Attributes\Setting\ArraySetting;
use Nadybot\Core\DBSchema\{
	Audit,
	CmdCfg,
	EventCfg,
	HlpCfg,
	Setting,
};
use Nadybot\Core\{
	Attributes as NCA,
	Channels\PrivateChannel,
	Channels\PrivateMessage,
	Config\BotConfig,
	Modules\BAN\BanController,
	Modules\LIMITS\LimitsController,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SettingHandler as CoreSettingHandler,
};
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Revolt\EventLoop;
use Throwable;

/**
 * Ignore non-camelCaps named methods as a lot of external calls rely on
 * them and we can't simply rename them
 *
 * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
 */

#[NCA\Instance]
class Nadybot {
	public const PING_IDENTIFIER = "Nadybot";
	public const UNKNOWN_ORG = 'Clan (name unknown)';

	public Multi $aoClient;

	public BotRunner $runner;

	public bool $ready = false;

	/** The currently logged in character or null if not logged in */
	public ?Character $char=null;

	/**
	 * Names of players in our private channel
	 *
	 * @var array<string,bool>
	 */
	public array $chatlist = [];

	/**
	 * Names of private channels we're in
	 *
	 * @var array<string,bool>
	 */
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
	 *
	 * @var array<string,int>
	 */
	public array $guildmembers = [];

	/** Time the bot was started */
	public int $startup;

	/**
	 * A list of channels that we ignore messages from
	 *
	 * Ignore Messages from Vicinity/IRRK New Wire/OT OOC/OT Newbie OOC...
	 *
	 * @var string[]
	 */
	public array $channelsToIgnore = [
		'IRRK News Wire', 'OT OOC', 'OT Newbie OOC', 'OT shopping 11-50',
		'Tour Announcements', 'Neu. Newbie OOC', 'Neu. shopping 11-50', 'Neu. OOC', 'Clan OOC',
		'Clan Newbie OOC', 'Clan shopping 11-50', 'OT German OOC', 'Clan German OOC', 'Neu. German OOC',
	];

	/**
	 * A lookup cache for group id => group name
	 *
	 * @var array<string,string>
	 */
	public array $groupIdToName = [];

	/**
	 * A lookup cache for group name => id
	 *
	 * @var array<string,Group\Id>
	 */
	public array $groupNameToId = [];

	/** @var int[] */
	public array $buddyQueue = [];

	protected int $started = 0;

	protected int $numSpamMsgsSent = 0;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private AccountUnfreezer $accountUnfreezer;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private EventFeed $eventFeed;

	#[NCA\Inject]
	private HttpClientBuilder $http;

	#[NCA\Inject]
	private LimitsController $limitsController;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<int,string> */
	private array $uidToName = [];

	/** @var array<string,int> */
	private array $nameToUid = [];

	private ?Group $orgGroup = null;

	/** How many buddies can this bot hold */
	private int $buddyListSize = 0;

	/** Is the bot currently trying to stop? */
	private bool $shuttingDown = false;

	/** Initialize the bot */
	public function init(BotRunner $runner): void {
		$this->started = time();
		$this->runner = $runner;

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
			->each(function (CmdCfg $row): void {
				$this->existing_subcmds[$row->cmd] = true;
			});

		$this->db->table(EventManager::DB_TABLE)->asObj(EventCfg::class)
			->each(function (EventCfg $row): void {
				$this->existing_events[$row->type??""][$row->file??""] = true;
			});

		$this->db->table(HelpManager::DB_TABLE)->asObj(HlpCfg::class)
			->each(function (HlpCfg $row): void {
				$this->existing_helps[$row->name] = true;
			});

		$this->existing_settings = [];
		$this->db->table(SettingManager::DB_TABLE)->asObj(Setting::class)
			->each(function (Setting $row): void {
				$this->existing_settings[$row->name] = true;
			});

		$this->db->beginTransaction();
		$allClasses = get_declared_classes();
		foreach ($allClasses as $class) {
			$this->registerEvents($class);
			$this->registerSettingHandlers($class);
		}
		$this->db->commit();
		EventLoop::setErrorHandler(function (Throwable $e): void {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
		});
		$this->db->beginTransaction();
		// $jobs = [];
		$start = \Amp\now();
		foreach (Registry::getAllInstances() as $name => $instance) {
			if ($instance instanceof ModuleInstanceInterface && $instance->getModuleName() !== "") {
				$this->registerInstance($name, $instance);
				// $jobs []= async($this->registerInstance(...), $name, $instance);
			} else {
				$this->callSetupMethod($name, $instance);
				// $jobs []= async($this->callSetupMethod(...), $name, $instance);
			}
			if (!$this->db->inTransaction()) {
				$this->db->beginTransaction();
			}
		}
		if ($this->db->inTransaction()) {
			$this->db->commit();
		}
		// $this->logger->notice("Running {num_setups} setups in parallel", [
		// 	"num_setups" => count($jobs),
		// ]);
		// await($jobs);
		$duration = \Amp\now() - $start;
		$this->logger->notice("Setups done in {duration}s", [
			"duration" => number_format($duration, 3),
		]);
		$reaper = EventLoop::delay(60, function (string $identifier): void {
			if ($this->db->inTransaction()) {
				$this->logger->warning("Open transaction detected!");
			}
			$this->logger->warning("Killing hanging jobs");
			foreach (EventLoop::getIdentifiers() as $identifier) {
				if (EventLoop::isEnabled($identifier) && EventLoop::isReferenced($identifier)) {
					EventLoop::cancel($identifier);
				}
			}
		});
		EventLoop::unreference($reaper);
		EventLoop::run();
		EventLoop::cancel($reaper);
		$this->settingManager::$isInitialized = true;

		// Delete old entries in the DB
		$this->db->table(CommandManager::DB_TABLE)->where("verify", 0)
			->asObj(CmdCfg::class)
			->each(function (CmdCfg $row): void {
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
			->each(function (Setting $row): void {
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

	public function cacheUidNameMapping(string $name, int $uid): void {
		$name = Utils::normalizeCharacter($name);
		$this->uidToName[$uid] = $name;
		$this->nameToUid[$name] = $uid;
	}

	public function getOrgGroup(): ?Group {
		return $this->orgGroup;
	}

	/** @return array<string,Group> */
	public function getGroups(): array {
		return $this->aoClient->getGroups();
	}

	public function getGroupByName(string $group): ?Group {
		return $this->aoClient->getGroup($group);
	}

	public function getGroupById(string|Group\Id $groupId): ?Group {
		if (is_string($groupId)) {
			$parts = unpack("Ctype/Nid", $groupId);
			$groupId = new Group\Id(
				type: Group\Type::from($parts['type']),
				number: $parts['id'],
			);
		}
		return $this->aoClient->getGroup($groupId);
	}

	/** Connect to AO chat servers */
	public function connectAO(): void {
		$workers = [new WorkerConfig(
			dimension: $this->config->main->dimension,
			username: $this->config->main->login,
			password: $this->config->main->password,
			character: $this->config->main->character,
		)];
		foreach ($this->config->worker as $worker) {
			$workers []= new WorkerConfig(
				dimension: $worker->dimension,
				username: $worker->login,
				password: $worker->password,
				character: $worker->character,
			);
		}

		$this->aoClient = new Multi(
			workers: $workers,
			mainCharacter: $this->config->main->character,
			logger: $this->logger,
		);
		$this->aoClient->login();
		$this->char = new Character(
			name: $this->config->main->character,
			id: $this->getUid($this->config->main->character),
			dimension: $this->config->main->dimension
		);

		$this->buddyListSize = count($workers) * 1000;
		$this->logger->notice("Successfully logged in", [
			"name" => $this->config->main->character,
			"login" => $this->config->main->login,
			"server" => $this->config->main->dimension,
		]);
		$pc = new PrivateChannel($this->config->main->character);
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

	/** The main endless-loop of the bot */
	public function run(): void {
		$this->aoClient->onReady(function (): void {
			$this->ready = true;
			$this->eventManager->executeConnectEvents();
		});
		$this->eventFeed->mainLoop();
		EventLoop::setErrorHandler(function (Throwable $e): void {
			if ($e instanceof StopExecutionException) {
				return;
			}
			$this->logger->error($e->getMessage(), ["exception" => $e]);
		});

		$signalHandler = function (): void {
			$this->logger->notice('Shutdown requested.');
			$this->shuttingDown = true;
			foreach (EventLoop::getIdentifiers() as $identifier) {
				try {
					EventLoop::disable($identifier);
				} catch (Throwable $e) {
				}
			}
		};
		if (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler($signalHandler, true);
		} else {
			EventLoop::onSignal(SIGTERM, $signalHandler);
			EventLoop::onSignal(SIGINT, $signalHandler);
		}
		async(function (): void {
			foreach ($this->aoClient->getPackages() as $package) {
				$this->processPackage($package);
			}
		});
		EventLoop::repeat(
			1,
			function (): void {
				$packageTimes = $this->aoClient->getLastPackageReceived();
				$pongTimes = $this->aoClient->getLastPongSent();
				foreach ($packageTimes as $worker => $time) {
					if (microtime(true) - $time < 60) {
						continue;
					}
					if (microtime(true) - ($pongTimes[$worker]??0) < 60) {
						continue;
					}
					$this->sendPong($worker);
				}
			}
		);
		EventLoop::run();
		$this->logger->notice('Graceful shutdown.');
	}

	public function isShuttingDown(): bool {
		return $this->shuttingDown;
	}

	/** @return never */
	public function restart(): void {
		$this->aoClient->disconnect();
		$this->logger->notice("The Bot is restarting.");
		$this->shuttingDown = true;
		exit(-1);
	}

	/** @return never */
	public function shutdown(): void {
		$this->aoClient->disconnect();
		$this->logger->notice("The Bot is shutting down.");
		$this->shuttingDown = true;
		exit(10);
	}

	/**
	 * Send a message to a private channel
	 *
	 * @param string|string[] $message      One or more messages to send
	 * @param bool            $disableRelay Set to true to disable relaying the message into the org/guild channel
	 * @param string          $group        Name of the private group to send message into or null for the bot's own
	 */
	public function sendPrivate($message, bool $disableRelay=false, ?string $group=null, bool $addDefaultColor=true): void {
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendPrivate($page, $disableRelay, $group);
			}
			return;
		}

		if ($group === null) {
			$group = $this->config->main->character;
		}
		$uid = $this->getUid($group);
		if (!isset($uid)) {
			return;
		}

		if ($this->settingManager->getBool("priv_channel_colors")) {
			$message = $this->text->formatMessage($origMsg = $message);
		} else {
			$message = $this->text->stripColors($origMsg = $message);
		}
		$privColor = "";
		if ($addDefaultColor) {
			$privColor = $this->settingManager->getString('default_priv_color') ?? "";
		}

		$this->aoClient->write(
			new Package\Out\PrivateChannelMessage(
				channelId: $uid,
				message: $privColor.$message
			)
		);
		$event = new AOChatEvent();
		$event->type = "sendpriv";
		$event->channel = $group;
		$event->message = $origMsg;
		$event->sender = $this->config->main->character;
		$this->eventManager->fireEvent($event, $disableRelay);
		if (!$disableRelay) {
			$rMessage = new RoutableMessage($origMsg);
			$rMessage->setCharacter(new Character($this->config->main->character, $this->char?->id));
			$label = null;
			if (strlen($this->config->general->orgName)) {
				$label = "Guest";
			}
			$rMessage->prependPath(new Source(Source::PRIV, $this->config->main->character, $label));
			$this->messageHub->handle($rMessage);
		}
	}

	/**
	 * Send one or more messages into the org/guild channel
	 *
	 * @param string|string[] $message      One or more messages to send
	 * @param bool            $disableRelay Set to true to disable relaying the message into the bot's private channel
	 * @param int             $priority     The priority of the message or medium if unset
	 */
	public function sendGuild($message, bool $disableRelay=false, ?int $priority=null, bool $addDefaultColor=true): void {
		if (!isset($this->orgGroup) || $this->settingManager->get('guild_channel_status') != 1) {
			return;
		}

		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendGuild($page, $disableRelay, $priority);
			}
			return;
		}

		$priority ??= QueueInterface::PRIORITY_MED;

		if ($this->settingManager->getBool("guild_channel_colors")) {
			$message = $this->text->formatMessage($origMsg = $message);
		} else {
			$message = $this->text->stripColors($origMsg = $message);
		}
		$guildColor = "";
		if ($addDefaultColor) {
			$guildColor = $this->settingManager->getString("default_guild_color")??"";
		}

		$this->aoClient->write(
			package: new Package\Out\GroupMessage(
				groupId: $this->orgGroup->id,
				message: $guildColor.$message,
			)
		);
		$event = new AOChatEvent();
		$event->type = "sendguild";
		$event->channel = $this->config->general->orgName;
		$event->message = $origMsg;
		$event->sender = $this->config->main->character;
		$this->eventManager->fireEvent($event, $disableRelay);

		if ($disableRelay) {
			return;
		}
		$rMessage = new RoutableMessage($origMsg);
		$rMessage->setCharacter(new Character($this->config->main->character, $this->char?->id));
		$abbr = $this->settingManager->getString('relay_guild_abbreviation');
		$rMessage->prependPath(new Source(
			Source::ORG,
			$this->config->general->orgName,
			($abbr === 'none') ? null : $abbr
		));
		$this->messageHub->handle($rMessage);
	}

	public function sendRawTell(int|string $character, string $message, ?int $priority=null, ?string $worker=null): bool {
		if (is_string($character)) {
			$character = $this->getUid(Utils::normalizeCharacter($character));
			if (!isset($character)) {
				return false;
			}
		}
		$this->aoClient->write(
			package: new Package\Out\Tell(
				charId: $character,
				message: $message,
			),
			worker: $worker,
		);
		return true;
	}

	/**
	 * Send one or more messages to another player/bot
	 *
	 * @param string|string[] $message       One or more messages to send
	 * @param string          $character     Name of the person to send the tell to
	 * @param int             $priority      The priority of the message or medium if unset
	 * @param bool            $formatMessage If set, replace tags with their corresponding colors
	 */
	public function sendTell($message, string $character, ?int $priority=null, bool $formatMessage=true): void {
		if ($this->config->proxy?->enabled
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

		$priority ??= QueueInterface::PRIORITY_MED;

		$rMessage = new RoutableMessage($message);
		$tellColor = "";
		if ($formatMessage) {
			if ($this->settingManager->getBool("tell_colors")) {
				$message = $this->text->formatMessage($message);
			} else {
				$message = $this->text->stripColors($message);
			}
			$tellColor = $this->settingManager->getString("default_tell_color")??"";
		}

		$this->logChat("Out. Msg.", $character, $message);
		$this->sendRawTell($character, $tellColor.$message);
		$event = new AOChatEvent();
		$event->type = "sendmsg";
		$event->channel = $character;
		$event->message = $message;
		$this->eventManager->fireEvent($event);
		$rMessage->setCharacter(new Character($this->config->main->character, $this->char?->id));
		$rMessage->prependPath(new Source(Source::TELL, $this->config->main->character));
		$this->messageHub->handle($rMessage);
	}

	/**
	 * Send a mass message via the chatproxy to another player/bot
	 *
	 * @param string|string[] $message
	 */
	public function sendMassTell($message, string $character, ?int $priority=null, bool $formatMessage=true, null|int|string $worker=null): void {
		$priority ??= QueueInterface::PRIORITY_HIGH;
		$numWorkers =count($this->config->worker);

		// If we're not using workers, or mass tells are disabled, this doesn't do anything
		if (($numWorkers === 0)
			|| !$this->settingManager->getBool('allow_mass_tells')) {
			$this->sendTell($message, $character, $priority, $formatMessage);
			return;
		}
		$this->numSpamMsgsSent++;
		$message = (array)$message;
		$sendToWorker = isset($worker)
			&& $this->settingManager->getBool('reply_on_same_worker');
		$sendByMsg = $this->settingManager->getBool('paging_on_same_worker')
			&& count($message) > 1;
		if ($sendToWorker) {
			if (is_int($worker)) {
				$worker = $this->config->worker[$worker]->character;
			}
		} elseif ($sendByMsg) {
			$worker = random_int(0, $numWorkers -1);
			$worker = $this->config->worker[$worker]->character;
		}
		foreach ($message as $page) {
			$tellColor = "";
			if ($formatMessage) {
				$page = $this->text->formatMessage($page);
				$tellColor = $this->settingManager->getString("default_tell_color")??"";
			}
			if (!is_string($worker) || (!$sendByMsg && !$sendToWorker)) {
				$worker = random_int(0, $numWorkers -1);
				$worker = $this->config->worker[$worker]->character;
			}
			$this->logChat("Out. Msg.", $character, $page);
			$this->sendRawTell($character, $tellColor.$page, null, $worker);
		}
	}

	/**
	 * Send one or more messages into a public channel
	 *
	 * @param string|string[] $message  One or more messages to send
	 * @param string          $channel  Name of the channel to send the message to
	 * @param int             $priority The priority of the message or medium if unset
	 */
	public function sendPublic($message, string $channel, ?int $priority=null): void {
		$group = $this->getGroupByName($channel);
		if (!isset($group)) {
			$this->logger->warning("Trying to send to unknown group '{group}'", [
				"group" => $channel,
			]);
			return;
		}
		// for when $text->makeBlob generates several pages
		if (is_array($message)) {
			foreach ($message as $page) {
				$this->sendPublic($page, $channel, $priority);
			}
			return;
		}

		$priority ??= QueueInterface::PRIORITY_MED;

		$message = $this->text->formatMessage($origMessage = $message);
		$guildColor = $this->settingManager->getString("default_guild_color")??"";

		$rMessage = new RoutableMessage($origMessage);
		$rMessage->setCharacter(new Character(
			$this->config->main->character,
			$this->char?->id
		));
		$rMessage->prependPath(new Source(Source::PUB, $channel));
		$this->messageHub->handle($rMessage);
		$this->aoClient->write(
			package: new Package\Out\GroupMessage(
				groupId: $group->id,
				message: $guildColor.$message,
			)
		);
	}

	/** Process an incoming message packet that the bot receives */
	public function processPackage(WorkerPackage $package): void {
		try {
			$this->processAllPackages($package);

			// event handlers
			switch ($package->package::class) {
				case Package\In\GroupJoined::class:
					$this->processGroupAnnounce($package);
					break;
				case Package\In\PrivateChannelClientJoined::class:
					$this->processPrivateChannelJoin($package);
					break;
				case Package\In\PrivateChannelClientLeft::class:
					$this->processPrivateChannelLeave($package);
					break;
				case Package\In\PrivateChannelKicked::class:
				case Package\In\PrivateChannelLeft::class:
					$this->processPrivateChannelKick($package);
					break;
				case Package\In\BuddyState::class:
					$this->processBuddyUpdate($package);
					break;
				case Package\In\BuddyRemoved::class:
					$this->processBuddyRemoved($package);
					break;
				case Package\In\Tell::class:
					$this->processPrivateMessage($package);
					break;
				case Package\In\PrivateChannelMessage::class:
					$this->processPrivateChannelMessage($package);
					break;
				case Package\In\GroupMessage::class:
					$this->processPublicChannelMessage($package);
					break;
				case Package\In\PrivateChannelInvited::class:
					$this->processPrivateChannelInvite($package);
					break;
				case Package\In\Ping::class:
					$this->processPingReply($package);
					break;
				case Package\In\SystemMessage::class:
					$this->processSystemMessage($package);
					break;
			}
		} catch (StopExecutionException $e) {
			$this->logger->info('Execution stopped prematurely', ["exception" => $e]);
		}
	}

	/** Fire associated events for a received packet */
	public function processAllPackages(WorkerPackage $package): void {
		// fire individual packets event
		$eventObj = new PackageEvent();
		$eventObj->type = "packet({$package->package->type->name})";
		$eventObj->packet = $package;
		$this->eventManager->fireEvent($eventObj);
	}

	/** Handle an incoming AOChatPacket::GROUP_ANNOUNCE packet */
	public function processGroupAnnounce(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\GroupJoined);
		$groupId = $package->package->groupId;
		$groupName = $package->package->groupName;
		$this->logger->info("Handling {packet}", ["packet" => $package->package]);
		$this->groupIdToName[$groupId->toBinary()] = $groupName;
		$this->groupNameToId[$groupName] = $groupId;
		if ($groupId->type === Group\Type::Org) {
			$this->orgGroup = new Group(
				id: $groupId,
				name: $groupName,
				flags: $package->package->flags
			);
			$this->config->orgId = $groupId->number;
			if ($this->config->general->autoOrgName) {
				$lastOrgName = $this->settingManager->getString('last_org_name') ?? self::UNKNOWN_ORG;
				if ($lastOrgName === self::UNKNOWN_ORG) {
					$lastOrgName = $this->config->general->orgName ?: self::UNKNOWN_ORG;
				}
				if ($groupName === self::UNKNOWN_ORG) {
					$this->config->general->orgName = $lastOrgName;
				} else {
					$this->config->general->orgName = $groupName;
					$this->settingManager->save('last_org_name', $groupName);
				}
			}
		}
	}

	/** Handle a player joining a private group */
	public function processPrivateChannelJoin(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\PrivateChannelClientJoined);
		$this->logger->info("Received {packet}", ["packet" => $package->package]);
		$eventObj = new AOChatEvent();
		$channel = $this->getName($package->package->channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID for {packet}", [
				"packet" => $package->package,
			]);
			return;
		}
		$sender = $this->getName($package->package->charId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID for {packet}", [
				"packet" => $package->package,
			]);
			return;
		}
		$this->updateLastOnline($package->package->charId, $sender, true);
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;
		$this->logger->info("Handling {packet}", ["packet" => $package->package]);

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "joinpriv";

			$this->logChat("Priv Group", -1, "{$sender} joined the channel.");
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->action = AccessManager::JOIN;
			$this->accessManager->addAudit($audit);

			if ($this->banController->isOnBanlist($package->package->charId)) {
				$kick = new Package\Out\PrivateChannelKick(charId: $package->package->charId);
				$this->aoClient->write($kick);
				$audit = new Audit();
				$audit->actor = $sender;
				$audit->action = AccessManager::KICK;
				$audit->value = "banned";
				$this->accessManager->addAudit($audit);
				return;
			}
			$this->chatlist[$sender] = true;
			$this->eventManager->fireEvent($eventObj);
		} elseif ($this->char?->id === $package->package->charId) {
			$eventObj->type = "extjoinpriv";

			$this->logger->notice("Joined the private channel {channel}.", [
				"channel" => $channel,
			]);
			$this->privateChats[$channel] = true;
			$pc = new PrivateChannel($channel);
			$this->messageHub
				->registerMessageEmitter($pc)
				->registerMessageReceiver($pc);
			$this->eventManager->fireEvent($eventObj);
		}
	}

	/** Handle a player leaving a private group */
	public function processPrivateChannelLeave(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\PrivateChannelClientLeft);
		$this->logger->info("Received {package}", ["package" => $package]);
		$eventObj = new AOChatEvent();
		$channel = $this->getName($package->package->channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID for {package}", ["package" => $package->package]);
			return;
		}
		$sender = $this->getName($package->package->charId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID for {package}", ["package" => $package->package]);
			return;
		}
		$this->updateLastOnline($package->package->charId, $sender, true);
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		if ($this->isDefaultPrivateChannel($channel)) {
			$eventObj->type = "leavepriv";

			$this->logChat("Priv Group", -1, "{$sender} left the channel.");

			// Remove from Chatlist array
			unset($this->chatlist[$sender]);

			$this->eventManager->fireEvent($eventObj);
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->action = AccessManager::LEAVE;
			$this->accessManager->addAudit($audit);
		} elseif ($this->char?->id === $package->package->charId) {
			unset($this->privateChats[$channel]);
		} else {
			$eventObj->type = "otherleavepriv";
			$this->eventManager->fireEvent($eventObj);
		}
	}

	/** Handle bot being kicked from private channel / leaving by itself */
	public function processPrivateChannelKick(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\PrivateChannelLeft
		|| $package->package instanceof Package\In\PrivateChannelKicked);
		$channel = $this->getName($package->package->channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID for {package}", [
				"package" => $package->{$package},
			]);
			return;
		}

		$this->logger->info("Handling {package}", ["package" => $package->package]);
		$this->logger->notice("Left the private channel {channel}.", ["channel" => $channel]);

		$eventObj = new AOChatEvent();
		$sender = $this->char?->name;
		assert(is_string($sender));
		$eventObj->channel = $channel;
		$eventObj->sender = $sender;
		$eventObj->type = "extleavepriv";

		unset($this->privateChats[$channel]);
		$this->messageHub
			->unregisterMessageEmitter(Source::PRIV . "({$channel})")
			->unregisterMessageReceiver(Source::PRIV . "({$channel})");

		$this->eventManager->fireEvent($eventObj);
	}

	public function updateLastOnline(int $userId, string $charName, bool $online=true): void {
		if ($online === false || $userId === $this->char?->id) {
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

	public function getWorkerId(string $worker): int {
		$workerId = array_search($worker, array_column($this->config->worker, "character"));
		if ($workerId === false) {
			$workerId = 0;
		}
		return $workerId + 1;
	}

	/** Handle logon/logoff events of friends */
	public function processBuddyUpdate(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\BuddyState);
		$userId = $package->package->charId;
		$sender = $this->getName($userId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid user ID for {package}", [
				"package" => $package->package,
			]);
			return;
		}
		$this->updateLastOnline($userId, $sender, $package->package->online);

		$eventObj = new UserStateEvent();
		$eventObj->uid = $userId;
		$eventObj->sender = $sender;

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		$worker = $package->worker;

		// If this UID was added via the queue, then every UID before its
		// queue entry is an inactive or non-existing player
		$queuePos = array_search($userId, $this->buddyQueue);
		if (count($this->config->worker) === 0 && $queuePos !== false) {
			$remUid = array_shift($this->buddyQueue);
			while (isset($remUid) && $remUid !== $userId) {
				$this->logger->info("Removing non-existing UID {user_id} from buddylist", [
					"user_id" => $remUid,
				]);
				$this->buddylistManager->updateRemoved($remUid);
				$remUid = array_shift($this->buddyQueue);
			}
		}
		$inRebalance = $this->buddylistManager->isRebalancing($userId);
		$eventObj->wasOnline = $this->buddylistManager->isOnline($sender);
		$workerId = $this->getWorkerId($worker);
		$this->buddylistManager->update($userId, $package->package->online, $workerId);

		// Ignore Logon/Logoff from other bots or phantom logon/offs
		if ($inRebalance || $sender === "") {
			return;
		}

		// Status => 0: logoff  1: logon
		$eventObj->type = "logon";
		if ($package->package->online) {
			$this->logger->info("{buddy} logged on", ["buddy" => $sender]);
		} else {
			$eventObj->type = "logoff";
			$this->logger->info("{buddy} logged off", ["buddy" => $sender]);
		}
		$this->eventManager->fireEvent($eventObj);
	}

	/** Handle that a friend was removed from the friendlist */
	public function processBuddyRemoved(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\BuddyRemoved);

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		$this->buddylistManager->updateRemoved($package->package->charId);
	}

	/** Handle an incoming tell */
	public function processPrivateMessage(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\Tell);
		$type = "msg";
		$senderId = $package->package->charId;
		$message = $package->package->message;
		$sender = $this->getName($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID in {package}", [
				"package" => $package->package,
			]);
			return;
		}
		$this->updateLastOnline($senderId, $sender, true);

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		// Removing tell color
		if (count($arr = Safe::pregMatch("/^<font color='#([0-9a-f]+)'>(.+)$/si", $message))) {
			$message = $arr[2];
		}
		// When we send commands via text->makeChatcmd(), the ' gets escaped
		// and we need to unescape it. But let's be sure by checking that
		// we haven't been passed some actual HTML
		if (strpos($message, '<') === false) {
			$message = str_replace('&#39;', "'", $message);
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->type = $type;
		$eventObj->message = $message;
		$workerId = $this->getWorkerId($package->worker);
		if ($workerId > 0) {
			$eventObj->worker = $workerId;
		}

		$this->logChat("Inc. Msg.", $sender, $message);

		// AFK/bot check
		if (preg_match("|{$sender} is AFK|si", $message)) {
			return;
		} elseif (preg_match("|I am away from my keyboard right now|si", $message)) {
			return;
		} elseif (preg_match("|Unknown command or access denied!|si", $message)) {
			return;
		} elseif (preg_match("|Command not found, try|si", $message)) {
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
		} elseif (preg_match("|/tell {$sender} !help|i", $message)) {
			return;
		}

		$rMsg = new RoutableMessage($message);
		$rMsg->appendPath(new Source(Source::TELL, $sender));
		$rMsg->setCharacter(new Character($sender, $senderId, $this->config->main->dimension));
		if ($this->messageHub->handle($rMsg) !== $this->messageHub::EVENT_NOT_ROUTED) {
			return;
		}

		if ($this->banController->isOnBanlist($senderId)) {
			return;
		}
		if ($this->eventManager->fireEvent($eventObj)) {
			return;
		}

		$context = new CmdContext($sender, $senderId);
		$context->message = $message;
		$context->source = Source::TELL . "({$sender})";
		$context->sendto = new PrivateMessageCommandReply($this, $sender, $eventObj->worker ?? null);
		$context->setIsDM();
		try {
			$this->limitsController->checkTellExecuteAccess($sender, $message);
		} catch (UserException $e) {
			$context->reply($e->getMessage());
			return;
		} catch (Throwable) {
			$context->reply("There was an error checking whether you are allowed to run commands.");
			return;
		}
		$this->commandManager->checkAndHandleCmd($context);
	}

	/** Handle a message on a private channel */
	public function processPrivateChannelMessage(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\PrivateChannelMessage);
		$senderId = $package->package->charId;
		$channelId = $package->package->channelId;
		$channel = $this->getName($channelId);
		if (!is_string($channel)) {
			$this->logger->info("Invalid channel ID in {package}", [
				"package" => $package->package,
			]);
			return;
		}
		$sender = $this->getName($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID in {package}", [
				"package" => $package->package,
			]);
			return;
		}
		$this->updateLastOnline($senderId, $sender, true);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel;
		$eventObj->message = $package->package->message;

		$this->logger->info("Handling {package}", ["package" => $package->package]);
		$this->logChat($channel, $sender, $package->package->message);

		if ($sender == $this->config->main->character) {
			return;
		}
		if ($this->isDefaultPrivateChannel($channel)) {
			$type = "priv";
		} else {  // ext priv group message
			$type = "extpriv";
		}
		$eventObj->type = $type;
		if ($this->eventManager->fireEvent($eventObj)) {
			return;
		}
		$rMessage = new RoutableMessage($package->package->message);
		$rMessage->setCharacter(new Character($sender, $senderId));
		$label = null;
		if (strlen($this->config->general->orgName)) {
			$label = "Guest";
		}
		$rMessage->prependPath(new Source(Source::PRIV, $channel, $label));
		$this->messageHub->handle($rMessage);
		$context = new CmdContext($sender, $senderId);
		$context->message = $package->package->message;
		$context->source = Source::PRIV . "({$channel})";
		$context->sendto = new PrivateChannelCommandReply($this, $channel);
		$this->commandManager->checkAndHandleCmd($context);
	}

	/** Handle a message on a public channel */
	public function processPublicChannelMessage(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\GroupMessage);
		$senderId = $package->package->charId;
		$channel = $this->getGroupById($package->package->groupId);
		if (!isset($channel)) {
			$this->logger->info("Invalid channel ID in {package}", [
				"package" => $package->package,
			]);
			return;
		}
		$sender = $this->getName($senderId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid sender ID in {package}", [
				"package" => $package,
			]);
			return;
		}
		$this->updateLastOnline($senderId, $sender, true);

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->channel = $channel->name;
		$eventObj->message = $package->package->message;

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		$isOrgMessage = $channel->id->type === Group\Type::Org;

		// Route public messages not from the bot itself
		if ($sender !== $this->config->main->character) {
			if (!$isOrgMessage || $this->settingManager->getBool('guild_channel_status')) {
				$rMessage = new RoutableMessage($package->package->message);
				if ($this->util->isValidSender($sender)) {
					$rMessage->setCharacter(new Character($sender, $senderId));
				}
				if ($isOrgMessage) {
					$abbr = $this->settingManager->getString('relay_guild_abbreviation');
					$rMessage->prependPath(new Source(
						Source::ORG,
						$channel->name,
						($abbr === 'none') ? null : $abbr
					));
				} else {
					$rMessage->prependPath(new Source(Source::PUB, $channel->name));
				}
				$this->messageHub->handle($rMessage);
			}
		}
		if (in_array($channel->name, $this->channelsToIgnore)) {
			return;
		}

		// don't log tower messages with rest of chat messages
		if ($channel != "All Towers" && $channel != "Tower Battle Outcome" && (!$isOrgMessage || $this->settingManager->getBool('guild_channel_status'))) {
			$this->logChat($channel->name, $sender, $package->package->message);
		} else {
			$this->logger->info("[{channel}]: {message}", [
				"channel" => $channel->name,
				"message" => $package->package->message,
			]);
		}

		// ignore messages that are sent from the bot self
		if ($sender == $this->config->main->character) {
			return;
		}

		if ($channel->name == "All Towers" || $channel->name == "Tower Battle Outcome") {
			$eventObj->type = "towers";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($channel->name == "Org Msg") {
			$eventObj->type = "orgmsg";

			$this->eventManager->fireEvent($eventObj);
		} elseif ($isOrgMessage && $this->settingManager->getBool('guild_channel_status')) {
			$type = "guild";

			$eventObj->type = $type;

			if ($this->eventManager->fireEvent($eventObj)) {
				return;
			}
			$context = new CmdContext($sender, $senderId);
			$context->source = Source::ORG;
			$context->message = $package->package->message;
			$context->sendto = new GuildChannelCommandReply($this);
			$this->commandManager->checkAndHandleCmd($context);
		}
	}

	/** Handle an invite to a private channel */
	public function processPrivateChannelInvite(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\PrivateChannelInvited);
		$sender = $this->getName($package->package->channelId);
		if (!is_string($sender)) {
			$this->logger->info("Invalid channel ID in {package}", [
				"package" => $package->package,
			]);
			return;
		}

		$eventObj = new AOChatEvent();
		$eventObj->sender = $sender;
		$eventObj->type = "extjoinprivrequest";

		$this->logger->info("Handling {package}", ["package" => $package->package]);

		$this->logChat("Priv Channel Invitation", -1, "{$sender} channel invited.");

		$this->eventManager->fireEvent($eventObj);
	}

	public function processPingReply(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\Ping);
		if ($package->package->extra === static::PING_IDENTIFIER) {
			return;
		}
		$this->eventManager->fireEvent(new PongEvent(0));
	}

	public function getUid(string $name, bool $cacheOnly=false): ?int {
		$name = Utils::normalizeCharacter($name);
		if (isset($this->nameToUid[$name])) {
			return $this->nameToUid[$name];
		}
		return $this->aoClient->lookupUid($name, $cacheOnly);
	}

	public function getName(int $uid, bool $cacheOnly=false): ?string {
		if (isset($this->uidToName[$uid])) {
			return $this->uidToName[$uid];
		}
		return $this->aoClient->lookupCharacter($uid, $cacheOnly);
	}

	/** Send a ping packet to keep the connection open */
	public function sendPong(?string $worker=null): void {
		$this->aoClient->write(
			package: new Package\Out\Pong(extra: self::PING_IDENTIFIER),
			worker: $worker,
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
	 */
	public function registerInstance(string $name, ModuleInstanceInterface $obj): void {
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
				if ($result === false) {
					$this->logger->error("Failed to call setup handler for '{class}'", [
						"class" => $name,
					]);
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
					$this->logger->warning("Cannot handle command '{command}' as it is not defined with #[DefineCommand] in '{class}'.", [
						"command" => $commandName,
						"class" => $name,
					]);
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

				/** @psalm-suppress TypeDoesNotContainNull */
				if (!isset($closure)) {
					continue;
				}
				$this->settingManager->registerChangeListener($change->setting, $closure);
			}
		}

		foreach ($commands as $command => $definition) {
			if (count($definition->handlers) === 0) {
				$this->logger->error("No handlers defined for command '{command}' in module '{module}'.", [
					"command" => $command,
					"module" => $moduleName,
				]);
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
				$this->logger->error("No handlers defined for subcommand '{subcommand}' in module '{module}'.", [
					"subcommand" => $subcommand,
					"module" => $moduleName,
				]);
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
	}

	public static function toSnakeCase(string $name): string {
		return strtolower(
			Safe::pregReplace(
				"/([A-Z][a-z])/",
				'_$1',
				Safe::pregReplace(
					"/([A-Z]{2,})(?=[A-Z][a-z]|$)/",
					'_$1',
					Safe::pregReplace(
						"/(\d+)$/",
						'_$1',
						$name
					)
				)
			)
		);
	}

	/** Call the setup method for an object */
	public function callSetupMethod(string $class, object $obj): void {
		$reflection = new ReflectionClass($obj);
		foreach ($reflection->getMethods() as $method) {
			if (empty($method->getAttributes(NCA\Setup::class))) {
				continue;
			}
			$result = $method->invoke($obj);
			if ($result === false) {
				$this->logger->error("Failed to call setup handler for '{class}'", [
					"class" => $class,
				]);
			}
		}
	}

	/** Get the amount of people allowed on our friendlist */
	public function getBuddyListSize(): int {
		return $this->buddyListSize;
	}

	/** Tells when the bot is logged on and all the start up events have finished */
	public function isReady(): bool {
		return $this->ready;
	}

	/** Check if a private channel is this bot's private channel */
	public function isDefaultPrivateChannel(string $channel): bool {
		return $channel === $this->char?->name;
	}

	public function getUptime(): int {
		return time() - $this->started;
	}

	/**
	 * Log a chat message, stripping potential HTML code from it
	 *
	 * @param string     $channel Either "Buddy" or an org or private-channel name
	 * @param string|int $sender  The name of the sender, or a number representing the channel
	 * @param string     $message The message to log
	 */
	public function logChat(string $channel, string|int $sender, string $message): void {
		if (!$this->config->general->showAomlMarkup) {
			$message = Safe::pregReplace("|<font.*?>|", "", $message);
			$message = Safe::pregReplace("|</font>|", "", $message);
			$message = Safe::pregReplace("|<a\\s+href=\".+?\">|s", "[link]", $message);
			$message = Safe::pregReplace("|<a\\s+href='.+?'>|s", "[link]", $message);
			$message = Safe::pregReplace("|<a\\s+href=.+?>|s", "[link]", $message);
			$message = Safe::pregReplace("|</a>|", "[/link]", $message);
		}

		if ($channel == "Buddy") {
			$line = "[{$channel}] {$sender} {$message}";
		} elseif ($sender == '-1' || $sender == '4294967295') {
			$line = "[{$channel}] {$message}";
		} else {
			$line = "[{$channel}] {$sender}: {$message}";
		}

		$this->logger->notice($line);
	}

	private function processSystemMessage(WorkerPackage $package): void {
		assert($package->package instanceof Package\In\SystemMessage);
		$infoGradeMsgs = [
			158601204 => true, // XXX is offline
			54583877 => true, // Could not send message to offline player
			170904871 => true, // Sending messages too fast
		];
		if (isset($infoGradeMsgs[$package->package->messageId])) {
			$this->logger->info("Chat notice: {message}", [
				"message" => $package->package->message,
			]);
			return;
		}
		$this->logger->notice("Chat notice: {message}", [
			"message" => $package->package->message,
			"message_id" => $package->package->messageId,
		]);
	}

	/**
	 * Parse all defined commands of the class and return them
	 *
	 * @return array<array<string,CmdDef>>
	 *
	 * @phpstan-return array{array<string,CmdDef>,array<string,CmdDef>}
	 */
	private function parseInstanceCommands(string $moduleName, ModuleInstanceInterface $obj): array {
		/**
		 * register commands, subcommands, and events annotated on the class
		 *
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
			$cmdParts  = explode(" ", $command . " ", 2);
			if (count($cmdParts) !== 2) {
				continue;
			}
			[$parentCommand, $subCommand] = $cmdParts;
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
			$attribute->name ??= self::toSnakeCase($property->getName());

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
			$comment = trim(Safe::pregReplace("|^/\*\*(.*)\*/|s", '$1', $comment));
			$comment = Safe::pregReplace("/^[ \t]*\*[ \t]*/m", '', $comment);
			$description = trim(Safe::pregReplace("/^@.*/m", '', $comment));
			$settingValue = $value = $attribute->getValue();
			if (is_array($settingValue)) {
				$settingValue = join("|", $settingValue);
			}
			$this->settingManager->add(
				module: $moduleName,
				name: $attribute->name,
				description: $description,
				mode: $attribute->mode,
				type: $attribute->type,
				value: $settingValue,
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
			case 'array':
				$attrs = $property->getAttributes(ArraySetting::class, ReflectionAttribute::IS_INSTANCEOF);
				foreach ($attrs as $attr) {
					$attrObj = $attr->newInstance();
					$property->setValue($obj, $attrObj->toArray($value));
				}
				return;
			default:
				throw new Exception(
					"Invalid type " . $type->getName() . " for ".
					$property->getDeclaringClass()->getName() . '::$' . $property->getName().
					" - cannot be bound to setting."
				);
		}
	}
}
