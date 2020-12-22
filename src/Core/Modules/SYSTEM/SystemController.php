<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	BuddylistManager,
	CommandAlias,
	CommandManager,
	CommandReply,
	DB,
	Event,
	EventManager,
	HelpManager,
	LoggerWrapper,
	Nadybot,
	PrivateMessageCommandReply,
	SettingManager,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Core\Annotations\Setting;
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
class SystemController {

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
	 * @Setting("access_denied_notify_guild")
	 * @Description("Notify guild channel when a player is denied access to a command in tell")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultAccessDeniedNotifyGuild = "1";
	
	/**
	 * @Setting("access_denied_notify_priv")
	 * @Description("Notify private channel when a player is denied access to a command in tell")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("true;false")
	 * @Intoptions("1;0")
	 * @AccessLevel("mod")
	 */
	public string $defaultAccessDeniedNotifyPriv = "1";

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
	}
	
	/**
	 * @HandlesCommand("restart")
	 * @Matches("/^restart$/i")
	 */
	public function restartCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = "Bot is restarting.";
		$this->chatBot->sendTell($msg, $sender);
		$this->chatBot->sendPrivate($msg, true);
		$this->chatBot->sendGuild($msg, true);

		$this->chatBot->disconnect();
		$this->logger->log('INFO', "The Bot is restarting.");
		exit(-1);
	}

	/**
	 * @HandlesCommand("shutdown")
	 * @Matches("/^shutdown$/i")
	 */
	public function shutdownCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = "The Bot is shutting down.";
		$this->chatBot->sendTell($msg, $sender);
		$this->chatBot->sendPrivate($msg, true);
		$this->chatBot->sendGuild($msg, true);

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

		$sql = "SELECT count(*) AS count FROM players";
		$row = $this->db->queryRow($sql);
		$stats->charinfo_cache_size = (int)$row->count;

		foreach ($this->buddylistManager->buddyList as $key => $value) {
			if (!isset($value['name'])) {
				// skip the buddies that have been added but the server hasn't sent back an update yet
				continue;
			}
			$stats->buddy_list_size++;
		}
		$stats->max_buddy_list_size = $this->chatBot->getBuddyListSize();
		$stats->priv_channel_size = count($this->chatBot->chatlist);
		$stats->org_size = count($this->chatBot->guildmembers);
		$stats->chatqueue_length = count($this->chatBot->chatqueue->queue);

		foreach ($this->chatBot->grp as $gid => $status) {
			$channel = new ChannelInfo();
			$channel->id = unpack("N", substr($gid, 1))[1];
			$channel->name = $this->chatBot->gid[$gid];
			$info->channels []= $channel;
		}

		return $info;
	}

	/**
	 * @HandlesCommand("system")
	 * @Matches("/^system$/i")
	 */
	public function systemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$blob .= "<tab>Uptime: <highlight>$date_string<end>\n\n";

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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("checkaccess")
	 * @Matches("/^checkaccess$/i")
	 * @Matches("/^checkaccess (.+)$/i")
	 */
	public function checkaccessCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = $sender;
		if (count($args) > 1) {
			$name = ucfirst(strtolower($args[1]));
			if (!$this->chatBot->get_uid($name)) {
				$sendto->reply("Character <highlight>{$name}<end> does not exist.");
				return;
			}
		}
	
		$accessLevel = $this->accessManager->getDisplayName($this->accessManager->getAccessLevelForCharacter($name));
	
		$msg = "Access level for <highlight>$name<end> is <highlight>$accessLevel<end>.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler clears outgoing chatqueue from all pending messages.
	 *
	 * @HandlesCommand("clearqueue")
	 * @Matches("/^clearqueue$/")
	 */
	public function clearqueueCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$num = 0;
		foreach ($this->chatBot->chatqueue->queue as $priority) {
			$num += count($priority);
		}
		$this->chatBot->chatqueue->queue = [];
	
		$sendto->reply("Chat queue has been cleared of $num messages.");
	}

	/**
	 * This command handler execute multiple commands at once, separated by pipes.
	 *
	 * @HandlesCommand("macro")
	 * @Matches("/^macro (.+)$/si")
	 */
	public function macroCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$commands = explode("|", $args[1]);
		foreach ($commands as $commandString) {
			$this->commandManager->process($channel, trim($commandString), $sender, $sendto);
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
		$sql = "SELECT * FROM settings_<myname> LIMIT 1";
		$this->db->fetch(Setting::class, $sql);
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
		$this->chatBot->sendGuild($msg, true);
		$this->chatBot->sendPrivate($msg, true);
	}
	
	/**
	 * @HandlesCommand("showcommand")
	 * @Matches("/^showcommand ([^ ]+) (.+)$/i")
	 */
	public function showCommandCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$cmd = $args[2];
		$type = "msg";
		if (!$this->chatBot->get_uid($name)) {
			$sendto->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}
	
		$showSendto = new PrivateMessageCommandReply($this->chatBot, $name);
		$this->commandManager->process($type, $cmd, $sender, $showSendto);
		
		$sendto->reply("Command <highlight>$cmd<end> has been sent to <highlight>$name<end>.");
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
