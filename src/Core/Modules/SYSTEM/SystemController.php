<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	Event,
	EventManager,
	HelpManager,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Nadybot,
	PrivateMessageCommandReply,
	SettingManager,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Core\Annotations\Setting;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

/**
 * @author Sebuda (RK2)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'checkaccess',
 *		accessLevel   = 'all',
 *		description   = 'Check effective access level of a character',
 *		help          = 'checkaccess.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'clearqueue',
 *		accessLevel   = 'mod',
 *		description   = 'Clear outgoing chatqueue from all pending messages',
 *		help          = 'clearqueue.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'macro',
 *		accessLevel   = 'all',
 *		description   = 'Execute multiple commands at once',
 *		help          = 'macro.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'showcommand',
 *		accessLevel   = 'mod',
 *		description   = 'Execute a command and have output sent to another player',
 *		help          = 'showcommand.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'system',
 *		accessLevel   = 'mod',
 *		description   = 'Show detailed information about the bot',
 *		help          = 'system.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'restart',
 *		accessLevel   = 'admin',
 *		description   = 'Restart the bot',
 *		help          = 'system.txt',
 *      defaultStatus = '1'
 *	)
 *	@DefineCommand(
 *		command       = 'shutdown',
 *		accessLevel   = 'admin',
 *		description   = 'Shutdown the bot',
 *		help          = 'system.txt',
 *		defaultStatus = '1'
 *	)
 */
class SystemController implements MessageEmitter {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setting("symbol")
	 * @Description("Command prefix symbol")
	 * @Visibility("edit")
	 * @Type("text")
	 * @Options("!;#;*;@;$;+;-")
	 * @AccessLevel("mod")
	 */
	public string $defaultSymbol = "!";

	/**
	 * @Setting("max_blob_size")
	 * @Description("Max chars for a window")
	 * @Visibility("edit")
	 * @Type("number")
	 * @Options("4500;6000;7500;9000;10500;12000")
	 * @AccessLevel("mod")
	 * @Help("max_blob_size.txt")
	 */
	public string $defaultMaxBlobSize = "7500";

	/**
	 * @Setting("http_timeout")
	 * @Description("Max time to wait for response from making http queries")
	 * @Visibility("edit")
	 * @Type("time")
	 * @Options("1s;2s;5s;10s;30s")
	 * @AccessLevel("mod")
	 */
	public string $defaultHttpTimeout = "10s";

	/**
	 * @Setting("guild_channel_status")
	 * @Description("Enable the guild channel")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultGuildChannelStatus = "1";

	/**
	 * @Setting("guild_channel_cmd_feedback")
	 * @Description("Show message on invalid command in guild channel")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultGuildChannelCmdFeedback = "1";

	/**
	 * @Setting("private_channel_cmd_feedback")
	 * @Description("Show message on invalid command in private channel")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultPrivateChannelCmdFeedback = "1";

	/**
	 * @Setting("version")
	 * @Description("Database version")
	 * @Visibility("noedit")
	 * @Type("text")
	 * @AccessLevel("mod")
	 */
	public string $defaultVersion = "0";

	/**
	 * @Setting("allow_mass_tells")
	 * @Description("When using the proxy, allow sending tells via the workers")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $allowMassTells = "1";

	/**
	 * @Setting("force_mass_tells")
	 * @Description("When using the proxy, always send tells via the workers")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $forceMassTells = "0";

	/**
	 * @Setting("reply_on_same_worker")
	 * @Description("When using the proxy, always reply via the worker that sent the tell")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $replyOnSameWorker = "0";

	/**
	 * @Setting("paging_on_same_worker")
	 * @Description("When using the proxy, always send multi-page replies via one worker ")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $pagingOnSameWorker = "1";

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->save('version', $this->chatBot->runner::getVersion());

		$this->helpManager->register($this->moduleName, "budatime", "budatime.txt", "all", "Format for budatime");

		$name = $this->chatBot->vars['name'];
		$this->settingManager->add(
			$this->moduleName,
			"default_private_channel",
			"Private channel to process commands from",
			"edit",
			"text",
			$name,
			$name
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(status)";
	}

	/**
	 * @HandlesCommand("restart")
	 */
	public function restartCommand(CmdContext $context): void {
		$msg = "Bot is restarting.";
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);

		$this->chatBot->disconnect();
		$this->logger->log('INFO', "The Bot is restarting.");
		exit(-1);
	}

	/**
	 * @HandlesCommand("shutdown")
	 */
	public function shutdownCommand(CmdContext $context): void {
		$msg = "The Bot is shutting down.";
		$this->chatBot->sendTell($msg, $context->char->name);
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);

		$this->chatBot->disconnect();
		$this->logger->log('INFO', "The Bot is shutting down.");
		exit(10);
	}

	public function getSystemInfo(): SystemInformation {
		$info = new SystemInformation();

		$info->basic = $basicInfo = new BasicSystemInformation();
		$basicInfo->bot_name = $this->chatBot->vars["name"];
		$basicInfo->bot_version = $this->chatBot->runner::getVersion();
		$basicInfo->db_type = $this->db->getType();
		$basicInfo->org = strlen($this->chatBot->vars['my_guild']??"")
			? $this->chatBot->vars['my_guild']
			: null;
		$basicInfo->org_id = $this->chatBot->vars['my_guild_id'] ?? null;
		$basicInfo->php_version = phpversion();
		$basicInfo->os = php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
		$basicInfo->superadmin = strlen($this->chatBot->vars["SuperAdmin"]??"")
			? $this->chatBot->vars["SuperAdmin"]
			: null;

		$info->memory = $memory = new MemoryInformation();
		$memory->current_usage = memory_get_usage();
		$memory->current_usage_real = memory_get_usage(true);
		$memory->peak_usage = memory_get_peak_usage();
		$memory->peak_usage_real = memory_get_peak_usage(true);

		$info->misc = $misc = new MiscSystemInformation();
		$misc->uptime = time() - $this->chatBot->vars['startup'];
		$misc->using_chat_proxy = ($this->chatBot->vars['use_proxy'] == 1);
		if ($misc->using_chat_proxy) {
			$misc->proxy_capabilities = $this->chatBot->proxyCapabilities;
		}

		$info->config = $config = new ConfigStatistics();
		$config->active_aliases = $numAliases = count($this->commandAlias->getEnabledAliases());
		foreach ($this->eventManager->events as $type => $events) {
			$config->active_events += count($events);
		}
		$config->active_tell_commands = (count($this->commandManager->commands['msg']) - $numAliases);
		$config->active_priv_commands = (count($this->commandManager->commands['priv']) - $numAliases);
		$config->active_org_commands = (count($this->commandManager->commands['guild']) - $numAliases);
		$config->active_subcommands = count($this->subcommandManager->subcommands);
		$config->active_help_commands = count($this->helpManager->getAllHelpTopics(null));

		$info->stats = $stats = new SystemStats();

		$query = $this->db->table("players");
		$row = $query->selectRaw($query->rawFunc("COUNT", "*", "count"))
			->asObj()->first();
		$stats->charinfo_cache_size = (int)$row->count;

		$stats->buddy_list_size = $this->buddylistManager->countConfirmedBuddies();
		$stats->max_buddy_list_size = $this->chatBot->getBuddyListSize();
		$stats->priv_channel_size = count($this->chatBot->chatlist);
		$stats->org_size = count($this->chatBot->guildmembers);
		$stats->chatqueue_length = count($this->chatBot->chatqueue->queue);

		foreach ($this->chatBot->grp as $gid => $status) {
			$channel = new ChannelInfo();
			$channel->id = unpack("N", substr((string)$gid, 1))[1];
			$channel->name = $this->chatBot->gid[$gid];
			$info->channels []= $channel;
		}

		return $info;
	}

	/**
	 * @HandlesCommand("system")
	 */
	public function systemCommand(CmdContext $context): void {
		$info = $this->getSystemInfo();

		$blob = "<header2>Basic Info<end>\n";
		$blob .= "<tab>Name: <highlight>{$info->basic->bot_name}<end>\n";
		if (isset($info->basic->superadmin)) {
			$blob .= "<tab>SuperAdmin: <highlight>{$info->basic->superadmin}<end>\n";
		} else {
			$blob .= "<tab>SuperAdmin: - <highlight>none<end> -\n";
		}
		if (isset($info->basic->org)) {
			$blob .= "<tab>Guild: <highlight>'{$info->basic->org}' ({$info->basic->org_id})<end>\n";
		} else {
			$blob .= "<tab>Guild: - <highlight>none<end> -\n";
		}

		$blob .= "<tab>Nadybot: <highlight>{$info->basic->bot_version}<end>\n";
		$blob .= "<tab>PHP: <highlight>{$info->basic->php_version}<end>\n";
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
		$blob .= "<tab>Bot Uptime: <highlight>$date_string<end>\n\n";

		$blob .= "<header2>Configuration<end>\n";
		$blob .= "<tab>Active tell commands: <highlight>{$info->config->active_tell_commands}<end>\n";
		$blob .= "<tab>Active private channel commands: <highlight>{$info->config->active_priv_commands}<end>\n";
		$blob .= "<tab>Active org channel commands: <highlight>{$info->config->active_org_commands}<end>\n";
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
		foreach ($info->channels as $channel) {
			$blob .= "<tab><highlight>{$channel->name}<end> ({$channel->id})\n";
		}

		$msg = $this->text->makeBlob('System Info', $blob);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("checkaccess")
	 */
	public function checkaccessSelfCommand(CmdContext $context): void {
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($context->char->name));

		$msg = "Access level for <highlight>{$context->char->name}<end> (".
			(isset($context->char->id) ? "ID {$context->char->id}" : "No ID").
			") is <highlight>$accessLevel<end>.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("checkaccess")
	 */
	public function checkaccessOtherCommand(CmdContext $context, PCharacter $name): void {
		$this->chatBot->getUid(
			$name(),
			function (?int $uid, CmdContext $context, string $name): void {
				if (!isset($uid)) {
					$context->reply("Character <highlight>{$name}<end> does not exist.");
					return;
				}
				$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($name));
				$msg = "Access level for <highlight>{$name}<end> (ID {$uid}) is <highlight>$accessLevel<end>.";
				$context->reply($msg);
				return;
			},
			$context,
			$name()
		);
		return;
	}

	/**
	 * This command handler clears outgoing chatqueue from all pending messages.
	 *
	 * @HandlesCommand("clearqueue")
	 */
	public function clearqueueCommand(CmdContext $context): void {
		$num = 0;
		foreach ($this->chatBot->chatqueue->queue as $priority) {
			$num += count($priority);
		}
		$this->chatBot->chatqueue->queue = [];

		$context->reply("Chat queue has been cleared of <highlight>{$num}<end> messages.");
	}

	/**
	 * This command handler execute multiple commands at once, separated by pipes.
	 *
	 * @HandlesCommand("macro")
	 */
	public function macroCommand(CmdContext $context, string $command): void {
		$commands = explode("|", $command);
		foreach ($commands as $commandString) {
			$context->message = $commandString;
			$this->commandManager->processCmd($context);
		}
	}

	/**
	 * @Event("timer(1hr)")
	 * @Description("This event handler is called every hour to keep MySQL connection active")
	 * @DefaultStatus("1")
	 */
	public function refreshMySQLConnectionEvent(Event $eventObj): void {
		// if the bot doesn't query the mysql database for 8 hours the db connection is closed
		$this->logger->log('DEBUG', "Pinging database");
		$this->db->table(SettingManager::DB_TABLE)
			->limit(1)
			->asObj(Setting::class)
			->first();
	}

	/**
	 * @Event("connect")
	 * @Description("Notify private channel, guild channel, and admins that bot is online")
	 * @DefaultStatus("1")
	 */
	public function onConnectEvent(Event $eventObj): void {
		// send Admin(s) a tell that the bot is online
		foreach ($this->adminManager->admins as $name => $info) {
			if ($info["level"] === 4 && $this->buddylistManager->isOnline($name)) {
				$this->chatBot->sendTell("<myname> is now <green>online<end>.", $name);
			}
		}

		$version = $this->chatBot->runner::getVersion();
		$msg = "Nadybot <highlight>$version<end> is now <green>online<end>.";

		// send a message to guild channel
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(Source::SYSTEM, "status"));
		$this->messageHub->handle($rMsg);
	}

	/**
	 * @HandlesCommand("showcommand")
	 */
	public function showCommandCommand(CmdContext $context, PCharacter $name, string $cmd): void {
		$this->chatBot->getUid($name(), [$this, "showCommandUid"], ...func_get_args());
	}

	public function showCommandUid(?int $uid, CmdContext $context, string $name, string $cmd): void {
		$name = ucfirst(strtolower($name));
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}

		$showSendto = new PrivateMessageCommandReply($this->chatBot, $name);
		$newContext = new CmdContext($context->char->name, $context->char->id);
		$newContext->sendto = $showSendto;
		$newContext->message = $cmd;
		$newContext->channel = "msg";
		$this->commandManager->processCmd($newContext);

		$context->reply("Command <highlight>{$cmd}<end> has been sent to <highlight>{$name}<end>.");
	}

	/**
	 * Get system information
	 * @Api("/sysinfo")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='SystemInformation', desc='Some basic system information')
	 */
	public function apiSysinfoGetEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse($this->getSystemInfo());
	}
}
