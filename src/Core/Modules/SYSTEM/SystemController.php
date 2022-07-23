<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Amp\File\createDefaultDriver;
use function Safe\unpack;

use Amp\Loop;
use Exception;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandManager,
	ConfigFile,
	DB,
	DBSchema\Setting,
	Event,
	EventManager,
	HelpManager,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	PrivateMessageCommandReply,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	HttpProtocolWrapper,
	Request,
	Response,
};

/**
 * @author Sebuda (RK2)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "checkaccess",
		accessLevel: "all",
		description: "Check effective access level of a character",
	),
	NCA\DefineCommand(
		command: "clearqueue",
		accessLevel: "mod",
		description: "Clear outgoing chatqueue from all pending messages",
	),
	NCA\DefineCommand(
		command: "macro",
		accessLevel: "guest",
		description: "Execute multiple commands at once",
	),
	NCA\DefineCommand(
		command: "showcommand",
		accessLevel: "mod",
		description: "Execute a command and have output sent to another player",
	),
	NCA\DefineCommand(
		command: "system",
		accessLevel: "mod",
		description: "Show detailed information about the bot",
	),
	NCA\DefineCommand(
		command: "restart",
		accessLevel: "admin",
		description: "Restart the bot",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "shutdown",
		accessLevel: "admin",
		description: "Shutdown the bot",
		defaultStatus: 1
	),
	NCA\DefineCommand(
		command: "showconfig",
		accessLevel: "admin",
		description: "Show a cleaned up version of your current config file",
		defaultStatus: 1
	),
]
class SystemController extends ModuleInstance implements MessageEmitter {
	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public AdminManager $adminManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Default command prefix symbol */
	#[NCA\Setting\Text(options: ["!", "#", "*", "@", "$", "+", "-"])]
	public string $symbol = "!";

	/** Max chars for a window */
	#[NCA\Setting\Number(
		options: [4500, 6000, 7500, 9000, 10500, 12000],
		help: "max_blob_size.txt",
	)]
	public int $maxBlobSize = 7500;

	/** Add header-ranges to multi-page replies */
	#[NCA\Setting\Boolean]
	public bool $addHeaderRanges = false;

	/** Max time to wait for response from making http queries */
	#[NCA\Setting\Time(options: ["1s", "2s", "5s", "10s", "30s"])]
	public int $httpTimeout = 10;

	/** Enable the guild channel */
	#[NCA\Setting\Boolean]
	public bool $guildChannelStatus = true;

	/** Database version */
	#[NCA\Setting\Text(mode: "noedit")]
	public string $version = "0";

	/** When using the proxy, allow sending tells via the workers */
	#[NCA\Setting\Boolean]
	public bool $allowMassTells = true;

	/** When using the proxy, always send tells via the workers */
	#[NCA\Setting\Boolean]
	public bool $forceMassTells = false;

	/** When using the proxy, always reply via the worker that sent the tell */
	#[NCA\Setting\Boolean]
	public bool $replyOnSameWorker = false;

	/** When using the proxy, always send multi-page replies via one worker */
	#[NCA\Setting\Boolean]
	public bool $pagingOnSameWorker = true;

	/** Display name for the rank "superadmin" */
	#[NCA\Setting\Text]
	public string $rankNameSuperadmin = "superadmin";

	/** Display name for the rank "admin" */
	#[NCA\Setting\Text]
	public string $rankNameAdmin = "administrator";

	/** Display name for the rank "moderator" */
	#[NCA\Setting\Text]
	public string $rankNameMod = "moderator";

	/** Display name for the rank "guild" */
	#[NCA\Setting\Text]
	public string $rankNameGuild = "guild";

	/** Display name for the rank "member" */
	#[NCA\Setting\Text]
	public string $rankNameMember = "member";

	/** Display name for the rank "guest" */
	#[NCA\Setting\Text]
	public string $rankNameGuest = "guest";

	/** Display name for the temporary rank "raidleader" */
	#[NCA\Setting\Text]
	public string $rankNameRL = "raidleader";

	#[
		NCA\SettingChangeHandler("rank_name_superadmin"),
		NCA\SettingChangeHandler("rank_name_admin"),
		NCA\SettingChangeHandler("rank_name_mod"),
		NCA\SettingChangeHandler("rank_name_guild"),
		NCA\SettingChangeHandler("rank_name_member"),
		NCA\SettingChangeHandler("rank_name_guest"),
		NCA\SettingChangeHandler("rank_name_rl"),
	]
	public function preventRankNameDupes(string $setting, string $old, string $new): void {
		$new = strtolower($new);
		if (strtolower($this->rankNameSuperadmin) === $new
			|| strtolower($this->rankNameAdmin) === $new
			|| strtolower($this->rankNameMod) === $new
			|| strtolower($this->rankNameGuild) === $new
			|| strtolower($this->rankNameMember) === $new
			|| strtolower($this->rankNameGuest) === $new
			|| strtolower($this->rankNameRL) === $new
		) {
			throw new Exception("The display name <highlight>{$new}<end> is already used for another rank.");
		}
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->helpManager->register($this->moduleName, "budatime", "budatime.txt", "all", "Format for budatime");

		$this->settingManager->save('version', $this->chatBot->runner::getVersion());

		$this->messageHub->registerMessageEmitter($this);
	}

	#[NCA\Event(
		name: "timer(1h)",
		description: "Warn if the buddylist is full",
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
			"You need to setup AOChatProxy (https://github.com/Nadybot/aochatproxy) ".
			"to support more than 1000 buddies."
		);
		$msg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($msg);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(status)";
	}

	/** Restart the bot */
	#[NCA\HandlesCommand("restart")]
	#[NCA\Help\Group("restart")]
	public function restartCommand(CmdContext $context): void {
		$msg = "Bot is restarting.";
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);

		$this->chatBot->restart();
	}

	/** Shutdown the bot. Configured properly, it won't start again */
	#[NCA\HandlesCommand("shutdown")]
	#[NCA\Help\Group("restart")]
	public function shutdownCommand(CmdContext $context): void {
		$msg = "The Bot is shutting down.";
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);

		$this->chatBot->shutdown();
	}

	public function getSystemInfo(): SystemInformation {
		$info = new SystemInformation();

		$info->basic = $basicInfo = new BasicSystemInformation();
		$basicInfo->bot_name = $this->chatBot->char->name;
		$basicInfo->bot_version = $this->chatBot->runner::getVersion();
		$basicInfo->db_type = $this->db->getType();
		$basicInfo->org = strlen($this->config->orgName)
			? $this->config->orgName
			: null;
		$basicInfo->org_id = $this->config->orgId;
		$basicInfo->php_version = phpversion();
		$basicInfo->os = php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
		$basicInfo->event_loop = class_basename(Loop::get());
		$basicInfo->fs = class_basename(createDefaultDriver());

		$basicInfo->superadmins = $this->config->superAdmins;

		$info->memory = $memory = new MemoryInformation();
		$memory->current_usage = memory_get_usage();
		$memory->current_usage_real = memory_get_usage(true);
		$memory->peak_usage = memory_get_peak_usage();
		$memory->peak_usage_real = memory_get_peak_usage(true);

		$info->misc = $misc = new MiscSystemInformation();
		$misc->uptime = time() - $this->chatBot->startup;
		$misc->using_chat_proxy = ($this->config->useProxy === 1);
		if ($misc->using_chat_proxy) {
			$misc->proxy_capabilities = $this->chatBot->proxyCapabilities;
		}

		$info->config = $config = new ConfigStatistics();
		$config->active_aliases = $numAliases = count($this->commandAlias->getEnabledAliases());
		foreach ($this->eventManager->events as $type => $events) {
			$config->active_events += count($events);
		}
		foreach ($this->commandManager->commands as $channel => $commands) {
			$chanStat = new ChannelCommandStats();
			$chanStat->name = $channel;
			$chanStat->active_commands = count($commands) - $numAliases;
			$config->active_commands []= $chanStat;
		}
		$config->active_subcommands = count($this->subcommandManager->subcommands);
		$config->active_help_commands = count($this->helpManager->getAllHelpTopics(null));

		$info->stats = $stats = new SystemStats();

		$stats->charinfo_cache_size = $this->db->table("players")->count();

		$stats->buddy_list_size = $this->buddylistManager->countConfirmedBuddies();
		$stats->max_buddy_list_size = $this->chatBot->getBuddyListSize();
		$stats->priv_channel_size = count($this->chatBot->chatlist);
		$stats->org_size = count($this->chatBot->guildmembers);
		$stats->chatqueue_length = 0;
		if (isset($this->chatBot->chatqueue)) {
			$stats->chatqueue_length = $this->chatBot->chatqueue->getSize();
		}

		foreach ($this->chatBot->grp as $gid => $status) {
			$channel = new ChannelInfo();
			$channel->class = ord(substr((string)$gid, 0, 1));
			$channel->id = unpack("N", substr((string)$gid, 1))[1];
			if (is_string($this->chatBot->gid[$gid])) {
				$channel->name = $this->chatBot->gid[$gid];
				$info->channels []= $channel;
			}
		}

		return $info;
	}

	/** Get an overview of the bot system */
	#[NCA\HandlesCommand("system")]
	public function systemCommand(CmdContext $context): void {
		$info = $this->getSystemInfo();

		$blob = "<header2>Basic Info<end>\n";
		$blob .= "<tab>Name: <highlight>{$info->basic->bot_name}<end>\n";
		if (empty($info->basic->superadmins)) {
			$blob .= "<tab>SuperAdmin: - <highlight>none<end> -\n";
		} else {
			$blob .= "<tab>SuperAdmin: <highlight>".
				(new Collection($info->basic->superadmins))->join("<end>, <highlight>", "<end> and <highlight>").
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
		$blob .= "<tab>Current Memory Usage: <highlight>" . $this->util->bytesConvert($info->memory->current_usage) . "<end>\n";
		$blob .= "<tab>Current Memory Usage (Real): <highlight>" . $this->util->bytesConvert($info->memory->current_usage_real) . "<end>\n";
		$blob .= "<tab>Peak Memory Usage: <highlight>" . $this->util->bytesConvert($info->memory->peak_usage) . "<end>\n";
		$blob .= "<tab>Peak Memory Usage (Real): <highlight>" . $this->util->bytesConvert($info->memory->peak_usage_real) . "<end>\n\n";

		$blob .= "<header2>Misc<end>\n";
		$date_string = $this->util->unixtimeToReadable($info->misc->uptime);
		$blob .= "<tab>Using Chat Proxy: <highlight>" . ($info->misc->using_chat_proxy ? "enabled" : "disabled") . "<end>\n";
		if ($info->misc->using_chat_proxy && $info->misc->proxy_capabilities->name !== "unknown") {
			$cap = $info->misc->proxy_capabilities;
			$blob .= "<tab>Proxy Software: <highlight>{$cap->name} {$cap->version}<end>\n";
			if (count($cap->supported_cmds)) {
				$blob .= "<tab>Supported commands <highlight>" . join("<end>, <highlight>", $cap->supported_cmds) . "<end>\n";
			}
			if (count($cap->send_modes)) {
				$blob .= "<tab>Supported send modes: <highlight>" . join("<end>, <highlight>", $cap->send_modes) . "<end>\n";
			}
			if (isset($cap->default_mode)) {
				$blob .= "<tab>Default send mode: <highlight>{$cap->default_mode}<end>\n";
			}
			if (isset($cap->workers) && count($cap->workers)) {
				$blob .= "<tab>Workers: <highlight>" . join("<end>, <highlight>", $cap->workers) . "<end>\n";
			}
			if (isset($cap->started_at)) {
				$blob .= "<tab>Proxy uptime: <highlight>".
					$this->util->unixtimeToReadable(time() - $cap->started_at).
					"<end>\n";
			}
		}
		$blob .= "<tab>Bot Uptime: <highlight>{$date_string}<end>\n\n";

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
		usort($info->channels, function (ChannelInfo $c1, ChannelInfo $c2): int {
			return ($c1->class <=> $c2->class) ?: $c1->id <=> $c2->id;
		});
		foreach ($info->channels as $channel) {
			$blob .= "<tab><highlight>{$channel->name}<end> ({$channel->class}:{$channel->id})\n";
		}

		$msg = $this->text->makeBlob('System Info', $blob);
		$context->reply($msg);
	}

	/** Show which access level you currently have */
	#[NCA\HandlesCommand("checkaccess")]
	public function checkaccessSelfCommand(CmdContext $context): void {
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($context->char->name));

		$msg = "Access level for <highlight>{$context->char->name}<end> (".
			(isset($context->char->id) ? "ID {$context->char->id}" : "No ID").
			") is <highlight>{$accessLevel}<end>.";
		$context->reply($msg);
	}

	/** Show which access level &lt;character&gt; currently has */
	#[NCA\HandlesCommand("checkaccess")]
	public function checkaccessOtherCommand(CmdContext $context, PCharacter $character): Generator {
		$uid = yield $this->chatBot->getUid2($character());
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$character}<end> does not exist.");
			return;
		}
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($character()));
		$msg = "Access level for <highlight>{$character}<end> (ID {$uid}) is <highlight>{$accessLevel}<end>.";
		$context->reply($msg);
	}

	/** Clears the outgoing chatqueue from all pending messages */
	#[NCA\HandlesCommand("clearqueue")]
	public function clearqueueCommand(CmdContext $context): void {
		if (!isset($this->chatBot->chatqueue)) {
			$context->reply("There is currently no Chat queue set up.");
			return;
		}
		$num = $this->chatBot->chatqueue->clear();

		$context->reply("Chat queue has been cleared of <highlight>{$num}<end> messages.");
	}

	/** Execute multiple commands at once, separated by pipes. */
	#[NCA\HandlesCommand("macro")]
	#[NCA\Help\Example(
		command: "<symbol>macro cmd That's all!|raid stop|kickall"
	)]
	#[NCA\Help\Epilogue("This command works especially well with aliases")]
	public function macroCommand(CmdContext $context, string $command): void {
		$commands = explode("|", $command);
		foreach ($commands as $commandString) {
			$context->message = $commandString;
			$this->commandManager->processCmd($context);
		}
	}

	#[NCA\Event(
		name: "timer(1hr)",
		description: "This event handler is called every hour to keep MySQL connection active",
		defaultStatus: 1
	)]
	public function refreshMySQLConnectionEvent(Event $eventObj): void {
		// if the bot doesn't query the mysql database for 8 hours the db connection is closed
		$this->logger->info("Pinging database");
		$this->db->table(SettingManager::DB_TABLE)
			->limit(1)
			->asObj(Setting::class)
			->first();
	}

	#[NCA\Event(
		name: "connect",
		description: "Notify private channel, guild channel, and admins that bot is online",
		defaultStatus: 1
	)]
	public function onConnectEvent(Event $eventObj): void {
		// send Admin(s) a tell that the bot is online
		foreach ($this->adminManager->admins as $name => $info) {
			if ($info["level"] === 4 && $this->buddylistManager->isOnline($name)) {
				$this->chatBot->sendTell("<myname> is now <on>online<end>.", $name);
			}
		}

		$version = $this->chatBot->runner::getVersion();
		$msg = "Nadybot <highlight>{$version}<end> is now <on>online<end>.";

		// send a message to guild channel
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);
	}

	/** Show  the output of &lt;cmd&gt; to &lt;name&gt; */
	#[NCA\HandlesCommand("showcommand")]
	#[NCA\Help\Example(
		command: "<symbol>showcommand Tyrence online",
		description: "Show the online list to Tyrence"
	)]
	public function showCommandCommand(CmdContext $context, PCharacter $name, string $cmd): Generator {
		$name = $name();
		$uid = yield $this->chatBot->getUid2($name);
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}

		$showSendto = new PrivateMessageCommandReply($this->chatBot, $name);
		$newContext = new CmdContext($context->char->name, $context->char->id);
		$newContext->sendto = $showSendto;
		$newContext->message = $cmd;
		$newContext->source = $context->source;
		$newContext->permissionSet = $context->permissionSet;
		$this->commandManager->processCmd($newContext);

		$context->reply("Command <highlight>{$cmd}<end> has been sent to <highlight>{$name}<end>.");
	}

	/** Show your current config file with sensitive information removed */
	#[NCA\HandlesCommand("showconfig")]
	public function showConfigCommand(CmdContext $context): void {
		$json = \Safe\json_encode(
			$this->config
				->except("password", "DB username", "DB password")
				->toArray(),
			JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
		);
		$context->reply(
			$this->text->makeBlob("Your config", $json)
		);
	}

	/** Get system information */
	#[
		NCA\Api("/sysinfo"),
		NCA\GET,
		NCA\AccessLevel("all"),
		NCA\ApiResult(code: 200, class: "SystemInformation", desc: "Some basic system information")
	]
	public function apiSysinfoGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->getSystemInfo());
	}
}
