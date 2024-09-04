<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Safe\{ini_get, json_encode};

use Amp\Http\Server\{Request, Response};
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Nadybot\Core\Attributes\Confidential;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Events\ConnectEvent;
use Nadybot\Core\Filesystem;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandManager,
	Config\BotConfig,
	DB,
	DBSchema\Setting,
	EventManager,
	Events\Event,
	HelpManager,
	MessageHub,
	ModuleInstance,
	Modules\BAN\BanController,
	Nadybot,
	ParamClass\PCharacter,
	PrivateMessageCommandReply,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	SettingManager,
	SubcommandManager,
	Text,
	Types\MessageEmitter,
	Types\SettingMode,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadylib\IMEX\TOML;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionObject;
use Revolt\EventLoop;

/**
 * @author Sebuda (RK2)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'checkaccess',
		accessLevel: 'all',
		description: 'Check effective access level of a character',
	),
	NCA\DefineCommand(
		command: 'clearqueue',
		accessLevel: 'mod',
		description: 'Clear outgoing chatqueue from all pending messages',
	),
	NCA\DefineCommand(
		command: 'macro',
		accessLevel: 'guest',
		description: 'Execute multiple commands at once',
	),
	NCA\DefineCommand(
		command: 'showcommand',
		accessLevel: 'mod',
		description: 'Execute a command and have output sent to another player',
	),
	NCA\DefineCommand(
		command: 'system',
		accessLevel: 'mod',
		description: 'Show detailed information about the bot',
	),
	NCA\DefineCommand(
		command: 'restart',
		accessLevel: 'admin',
		description: 'Restart the bot',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'shutdown',
		accessLevel: 'admin',
		description: 'Shutdown the bot',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'showconfig',
		accessLevel: 'admin',
		description: 'Show a cleaned up version of your current config file',
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: 'upgradeconfig',
		accessLevel: 'superadmin',
		description: 'Show a version of your current config file upgraded to latest standards',
		defaultStatus: 1
	),
]
class SystemController extends ModuleInstance implements MessageEmitter {
	/** Default command prefix symbol */
	#[NCA\Setting\Text(options: ['!', '#', '*', '@', '$', '+', '-'])]
	public string $symbol = '!';

	/** Max chars for a window */
	#[NCA\Setting\Number(
		options: [4_500, 6_000, 7_500, 9_000, 10_500, 12_000],
		help: 'max_blob_size.txt',
	)]
	public int $maxBlobSize = 7_500;

	/** Add header-ranges to multi-page replies */
	#[NCA\Setting\Boolean]
	public bool $addHeaderRanges = false;

	/** Database version */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $version = '0';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private AdminManager $adminManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private HelpManager $helpManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Setup]
	public function setup(): void {
		$this->helpManager->register($this->moduleName, 'budatime', 'budatime.txt', 'all', 'Format for budatime');

		$this->settingManager->save('version', $this->chatBot->runner::getVersion());

		$this->messageHub->registerMessageEmitter($this);
	}

	#[NCA\Event(
		name: 'timer(1h)',
		description: 'Warn if the buddylist is full',
		defaultStatus: 1,
	)]
	public function checkBuddylistFull(): void {
		$numBuddies = $this->buddylistManager->getUsedBuddySlots();
		$maxBuddies = $this->chatBot->getBuddyListSize();
		if ($numBuddies < $maxBuddies) {
			return;
		}
		$msg = new RoutableMessage(
			"The bot's buddylist is full ({$numBuddies}/{$maxBuddies}). ".
			'You need to setup AOChatProxy (https://github.com/Nadybot/aochatproxy) '.
			'to support more than 1000 buddies.'
		);
		$msg->appendPath(new Source(Source::SYSTEM, 'status'));
		$this->messageHub->handle($msg);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(status)';
	}

	/** Restart the bot */
	#[NCA\HandlesCommand('restart')]
	#[NCA\Help\Group('restart')]
	public function restartCommand(CmdContext $context): void {
		$msg = 'Bot is restarting.';
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, 'status'));
		$this->messageHub->handle($rMsg);

		$this->chatBot->restart();
	}

	/** Shutdown the bot. Configured properly, it won't start again */
	#[NCA\HandlesCommand('shutdown')]
	#[NCA\Help\Group('restart')]
	public function shutdownCommand(CmdContext $context): void {
		$msg = 'The Bot is shutting down.';
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, 'status'));
		$this->messageHub->handle($rMsg);

		$this->chatBot->shutdown();
	}

	public function getSystemInfo(): SystemInformation {
		$fsObj = $this->fs->getFilesystem();
		$fs = new ReflectionObject($fsObj);
		try {
			$driverProp = $fs->getProperty('driver');
			$fsClass = $driverProp->getValue($fsObj);
		} catch (ReflectionException) {
			$fsClass = 'Unknown';
		}

		$basicInfo = new BasicSystemInformation(
			bot_name: $this->config->main->character,
			workers: array_column($this->config->worker, 'character'),
			bot_version: $this->chatBot->runner::getVersion(),
			db_type: $this->db->getType()->value,
			org: strlen($this->config->general->orgName) ? $this->config->general->orgName : null,
			org_id: $this->config->orgId,
			php_version: \PHP_VERSION,
			os: php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
			event_loop: class_basename(EventLoop::getDriver()),
			fs: class_basename($fsClass),
			superadmins: $this->config->general->superAdmins,
		);
		$memoryLimit = ini_get('memory_limit');
		if (count($matches = Safe::pregMatch('/^(\d+)([kmg])$/i', $memoryLimit)) === 3) {
			if (strtolower($matches[2]) === 'm') {
				$memoryLimit = (int)$matches[1] * 1_024 * 1_024;
			} elseif (strtolower($matches[2]) === 'k') {
				$memoryLimit = (int)$matches[1] * 1_024;
			} elseif (strtolower($matches[2]) === 'g') {
				$memoryLimit = (int)$matches[1] * 1_024 * 1_024 * 1_024;
			} else {
				$memoryLimit = (int)$matches[1];
			}
		}

		$memoryInfo = new MemoryInformation(
			current_usage: memory_get_usage(),
			current_usage_real: memory_get_usage(true),
			peak_usage: memory_get_peak_usage(),
			peak_usage_real: memory_get_peak_usage(true),
			available: (int)$memoryLimit,
		);

		$miscInfo = new MiscSystemInformation(
			uptime: time() - $this->chatBot->startup,
			using_chat_proxy: $this->config->proxy?->enabled === true,
		);

		$configStats = new ConfigStatistics();
		$configStats->active_aliases = $numAliases = $this->commandAlias->getEnabledAliases()->count();
		foreach ($this->eventManager->events as $type => $events) {
			$configStats->active_events += count($events);
		}
		foreach ($this->commandManager->commands as $channel => $commands) {
			$configStats->active_commands []= new ChannelCommandStats(
				name: $channel,
				active_commands: count($commands) - $numAliases,
			);
		}
		$configStats->active_subcommands = count($this->subcommandManager->subcommands);
		$configStats->active_help_commands = iterator_count($this->helpManager->getAllHelpTopics(null));

		$systemStats = new SystemStats(
			charinfo_cache_size: $this->db->table(Player::getTable())->count(),
			buddy_list_size: $this->buddylistManager->countConfirmedBuddies(),
			max_buddy_list_size: $this->chatBot->getBuddyListSize(),
			priv_channel_size: count($this->chatBot->chatlist),
			org_size: count($this->chatBot->guildmembers),
			chatqueue_length: 0,
		);

		$channels = [];
		foreach ($this->chatBot->getGroups() as $name => $group) {
			$channels []= new ChannelInfo(
				class: $group->id->type->value,
				id: $group->id->number,
				name: $group->name,
			);
		}

		return new SystemInformation(
			basic: $basicInfo,
			memory: $memoryInfo,
			misc: $miscInfo,
			config: $configStats,
			stats: $systemStats,
			channels: $channels,
		);
	}

	/** Get an overview of the bot system */
	#[NCA\HandlesCommand('system')]
	public function systemCommand(CmdContext $context): void {
		$info = $this->getSystemInfo();

		$blob = "<header2>Basic Info<end>\n";
		$blob .= "<tab>Name: <highlight>{$info->basic->bot_name}<end>\n";
		if (count($info->basic->workers)) {
			$blob .= '<tab>Workers: <highlight>'.
				collect($info->basic->workers)->join('<end>, <highlight>', '<end> and <highlight>').
				"<end>\n";
		}
		if (!count($info->basic->superadmins)) {
			$blob .= "<tab>SuperAdmin: - <highlight>none<end> -\n";
		} else {
			$blob .= '<tab>SuperAdmin: <highlight>'.
				collect($info->basic->superadmins)->join('<end>, <highlight>', '<end> and <highlight>').
				"<end>\n";
		}
		if (isset($info->basic->org)) {
			$blob .= "<tab>Guild: <highlight>'{$info->basic->org}' ({$info->basic->org_id})<end>\n";
		} else {
			$blob .= "<tab>Guild: - <highlight>none<end> -\n";
		}

		$blob .= "<tab>Nadybot: <highlight>{$info->basic->bot_version}<end>\n";
		$blob .= "<tab>PHP: <highlight>{$info->basic->php_version}<end>\n";
		$blob .= "<tab>Event loop: <highlight>Amp {$info->basic->event_loop}<end> using ".
			"<highlight>{$info->basic->fs}<end> filesystem\n";
		$blob .= "<tab>OS: <highlight>{$info->basic->os}<end>\n";
		$blob .= "<tab>Database: <highlight>{$info->basic->db_type}<end>\n\n";

		$blob .= "<header2>Memory<end>\n";
		$blob .= '<tab>Available Memory for PHP: <highlight>' . Util::bytesConvert($info->memory->available) . "<end>\n";
		$blob .= '<tab>Current Memory Usage: <highlight>' . Util::bytesConvert($info->memory->current_usage) . "<end>\n";
		$blob .= '<tab>Current Memory Usage (Real): <highlight>' . Util::bytesConvert($info->memory->current_usage_real) . "<end>\n";
		$blob .= '<tab>Peak Memory Usage: <highlight>' . Util::bytesConvert($info->memory->peak_usage) . "<end>\n";
		$blob .= '<tab>Peak Memory Usage (Real): <highlight>' . Util::bytesConvert($info->memory->peak_usage_real) . "<end>\n\n";

		$blob .= "<header2>Misc<end>\n";
		$dateString = Util::unixtimeToReadable($info->misc->uptime);
		$blob .= '<tab>Using Chat Proxy: <highlight>' . ($info->misc->using_chat_proxy ? 'enabled' : 'disabled') . "<end>\n";
		$blob .= "<tab>Bot Uptime: <highlight>{$dateString}<end>\n\n";

		$blob .= "<header2>Configuration<end>\n";
		foreach ($info->config->active_commands as $cmdChannelStats) {
			$blob .= "<tab>Active {$cmdChannelStats->name} commands: <highlight>{$cmdChannelStats->active_commands}<end>\n";
		}
		$blob .= "<tab>Active subcommands: <highlight>{$info->config->active_subcommands}<end>\n";
		$blob .= "<tab>Active command aliases: <highlight>{$info->config->active_aliases}<end>\n";
		$blob .= "<tab>Active events: <highlight>{$info->config->active_events}<end>\n";
		$blob .= "<tab>Active help commands: <highlight>{$info->config->active_help_commands}<end>\n\n";

		$blob .= "<header2>Stats<end>\n";
		$blob .= "<tab>Characters on the buddy list: <highlight>{$info->stats->buddy_list_size}<end>\n";
		$blob .= "<tab>Maximum buddy list size: <highlight>{$info->stats->max_buddy_list_size}<end>\n";
		$blob .= "<tab>Characters in the private channel: <highlight>{$info->stats->priv_channel_size}<end>\n";
		$blob .= "<tab>Guild members: <highlight>{$info->stats->org_size}<end>\n";
		$blob .= "<tab>Character infos in cache: <highlight>{$info->stats->charinfo_cache_size}<end>\n";
		$blob .= "<tab>Messages in the chat queue: <highlight>{$info->stats->chatqueue_length}<end>\n\n";

		$blob .= "<header2>Public Channels<end>\n";
		usort($info->channels, static function (ChannelInfo $c1, ChannelInfo $c2): int {
			if ($c1->class === $c2->class) {
				return $c1->id <=> $c2->id;
			}
			return $c1->class <=> $c2->class;
		});
		foreach ($info->channels as $channel) {
			$blob .= "<tab><highlight>{$channel->name}<end> ({$channel->class}:{$channel->id})\n";
		}

		$msg = Text::makeBlob('System Info', $blob);
		$context->reply($msg);
	}

	/** Show which access level you currently have */
	#[NCA\HandlesCommand('checkaccess')]
	public function checkaccessSelfCommand(CmdContext $context): void {
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($context->char->name));

		$msg = "Access level for <highlight>{$context->char->name}<end> (".
			(isset($context->char->id) ? "ID {$context->char->id}" : 'No ID').
			") is <highlight>{$accessLevel}<end>.";
		if (isset($context->char->id)) {
			if ($this->banController->isOnBanlist($context->char->id)) {
				if ($this->banController->isBanned($context->char->id)) {
					$msg .= ' You are <red>banned<end> on this bot.';
				} else {
					$msg .= ' Your org is <red>banned<end> on this bot.';
				}
			}
		}
		$context->reply($msg);
	}

	/** Show which access level &lt;character&gt; currently has */
	#[NCA\HandlesCommand('checkaccess')]
	public function checkaccessOtherCommand(CmdContext $context, PCharacter $character): void {
		$uid = $this->chatBot->getUid($character());
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$character}<end> does not exist.");
			return;
		}
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($character()));
		$msg = "Access level for <highlight>{$character}<end> (ID {$uid}) is <highlight>{$accessLevel}<end>.";
		if ($this->banController->isOnBanlist($uid)) {
			if ($this->banController->isBanned($uid)) {
				$msg .= " {$character} is <red>banned<end> on this bot.";
			} else {
				$msg .= " {$character}'s org is <red>banned<end> on this bot.";
			}
		}
		$context->reply($msg);
	}

	/** Clears the outgoing chatqueue from all pending messages */
	#[NCA\HandlesCommand('clearqueue')]
	public function clearqueueCommand(CmdContext $context): void {
		/*
		if (!isset($this->chatBot->chatqueue)) {
			$context->reply("There is currently no Chat queue set up.");
			return;
		}
		$num = $this->chatBot->chatqueue->clear();

		$context->reply("Chat queue has been cleared of <highlight>{$num}<end> messages.");
		*/
		$context->reply('This command is currently unsupported');
	}

	/** Execute multiple commands at once, separated by pipes. */
	#[NCA\HandlesCommand('macro')]
	#[NCA\Help\Example(
		command: "<symbol>macro cmd That's all!|raid stop|kickall"
	)]
	#[NCA\Help\Epilogue('This command works especially well with aliases')]
	public function macroCommand(CmdContext $context, string $command): void {
		$commands = explode('|', $command);
		foreach ($commands as $commandString) {
			$context->message = $commandString;
			$this->commandManager->processCmd($context);
		}
	}

	#[NCA\Event(
		name: 'timer(1hr)',
		description: 'This event handler is called every hour to keep MySQL connection active',
		defaultStatus: 1
	)]
	public function refreshMySQLConnectionEvent(Event $eventObj): void {
		// if the bot doesn't query the mysql database for 8 hours the db connection is closed
		$this->logger->info('Pinging database');
		$this->db->table(Setting::getTable())
			->limit(1)
			->asObj(Setting::class)
			->first();
	}

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Notify private channel, guild channel, and admins that bot is online',
		defaultStatus: 1
	)]
	public function onConnectEvent(ConnectEvent $eventObj): void {
		// send Admin(s) a tell that the bot is online
		foreach ($this->adminManager->admins as $name => $info) {
			if ($info['level'] === 4 && $this->buddylistManager->isOnline($name) === true) {
				$this->chatBot->sendTell('<myname> is now <on>online<end>.', $name);
			}
		}

		$version = $this->chatBot->runner::getVersion();
		$msg = "Nadybot <highlight>{$version}<end> is now <on>online<end>.";

		// send a message to guild channel
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, 'status'));
		EventLoop::queue($this->messageHub->handle(...), $rMsg);
	}

	/** Show  the output of &lt;cmd&gt; to &lt;name&gt; */
	#[NCA\HandlesCommand('showcommand')]
	#[NCA\Help\Example(
		command: '<symbol>showcommand Tyrence online',
		description: 'Show the online list to Tyrence'
	)]
	public function showCommandCommand(CmdContext $context, PCharacter $name, string $cmd): void {
		$name = $name();
		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}

		$showSendto = new PrivateMessageCommandReply($this->chatBot, $name);
		$newContext = new CmdContext(
			charName: $context->char->name,
			charId: $context->char->id,
			sendto: $showSendto,
			message: $cmd,
			source: $context->source,
			permissionSet: $context->permissionSet,
		);
		$this->commandManager->processCmd($newContext);

		$context->reply("Command <highlight>{$cmd}<end> has been sent to <highlight>{$name}<end>.");
	}

	/** Show your current config file with sensitive information removed */
	#[NCA\HandlesCommand('showconfig')]
	public function showConfigCommand(CmdContext $context): void {
		$mapper = new ObjectMapperUsingReflection();
		Confidential::$active = true;
		$config = $mapper->serializeObject($this->config);
		Confidential::$active = false;

		$json = json_encode(
			$config,
			\JSON_PRETTY_PRINT|\JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE
		);
		$context->reply(
			Text::makeBlob('Your config', $json)
		);
	}

	/** Show your current configuration in the latest format */
	#[NCA\HandlesCommand('upgradeconfig')]
	public function upgradeConfigCommand(CmdContext $context): void {
		if (!$context->isDM()) {
			$context->reply('For security reasons, this command only works in tells');
			return;
		}
		$mapper = new ObjectMapperUsingReflection();
		Confidential::$active = false;
		$vars = $mapper->serializeObject($this->config);
		if (!isset($vars['org_id'])) {
			unset($vars['org_id']);
		}
		if (!isset($vars['proxy'])) {
			unset($vars['proxy']);
		}
		$toml = TOML::export($vars);
		$oldFilePath = $this->config->getFilePath();
		$newFilePath = Safe::pregReplace('/\.[^.]+$/', '', $oldFilePath) . '.toml';
		$context->reply(
			'Your upgraded config for '.
			Text::makeBlob(
				$newFilePath,
				"Copy the following and save it as <highlight>{$newFilePath}<end>.\n".
				"Make sure to use <highlight>{$newFilePath}<end> as your new config ".
				"file from then on:\n\n<highlight>" . trim($toml) . '<end>',
				'Your new configuration'
			)
		);
	}

	/** Get system information */
	#[
		NCA\Api('/sysinfo'),
		NCA\GET,
		NCA\AccessLevel('all'),
		NCA\ApiResult(code: 200, class: 'SystemInformation', desc: 'Some basic system information')
	]
	public function apiSysinfoGetEndpoint(Request $request): Response {
		return ApiResponse::create($this->getSystemInfo());
	}
}
