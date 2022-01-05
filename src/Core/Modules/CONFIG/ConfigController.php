<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use function Safe\file_get_contents;
use function Safe\glob;
use Exception;
use Illuminate\Support\Collection;
use ReflectionClass;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	EventManager,
	HelpManager,
	ModuleInstance,
	InsufficientAccessException,
	LoggerWrapper,
	Nadybot,
	QueryBuilder,
	Registry,
	SettingHandler,
	SettingManager,
	SubcommandManager,
	Text,
};
use Nadybot\Core\DBSchema\{
	EventCfg,
	CmdCfg,
	Setting,
};
use Nadybot\Core\ParamClass\PWord;

#[
	NCA\DefineCommand(
		command: "config",
		accessLevel: "mod",
		description: "Configure bot settings",
		help: "config.txt",
		defaultStatus: 1
	),
	NCA\Instance
]
class ConfigController extends ModuleInstance {

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {

		// construct list of command handlers
		$filename = [];
		$reflectedClass = new ReflectionClass($this);
		$className = Registry::formatName(get_class($this));
		foreach ($reflectedClass->getMethods() as $reflectedMethod) {
			if (preg_match('/command$/i', $reflectedMethod->name)) {
				$filename []= "{$className}.{$reflectedMethod->name}";
			}
		}
		$filename = implode(',', $filename);

		$this->commandManager->activate("msg", $filename, "config", "mod");
		$this->commandManager->activate("guild", $filename, "config", "mod");
		$this->commandManager->activate("priv", $filename, "config", "mod");

		$this->helpManager->register($this->moduleName, "config", "config.txt", "mod", "Configure Commands/Events");
	}

	/**
	 * This command handler lists list of modules which can be configured.
	 */
	#[NCA\HandlesCommand("config")]
	public function configCommand(CmdContext $context): void {
		$blob = "<header2>Quick config<end>\n".
			"<tab>Org Commands [" .
				$this->text->makeChatcmd('enable all', '/tell <myname> config cmd enable guild') . "] [" .
				$this->text->makeChatcmd('disable all', '/tell <myname> config cmd disable guild') . "]\n" .
			"<tab>Private Channel Commands [" .
				$this->text->makeChatcmd('enable all', '/tell <myname> config cmd enable priv') . "] [" .
				$this->text->makeChatcmd('disable all', '/tell <myname> config cmd disable priv') . "]\n" .
			"<tab>Private Message Commands [" .
				$this->text->makeChatcmd('enable all', '/tell <myname> config cmd enable msg') . "] [" .
				$this->text->makeChatcmd('disable all', '/tell <myname> config cmd disable msg') . "]\n" .
			"<tab>ALL Commands [" .
				$this->text->makeChatcmd('enable all', '/tell <myname> config cmd enable all') . "] [" .
				$this->text->makeChatcmd('disable all', '/tell <myname> config cmd disable all') . "]\n\n\n";
		$modules = $this->getModules();

		foreach ($modules as $module) {
			$numEnabled = $module->num_commands_enabled + $module->num_events_enabled;
			$numDisabled = $module->num_commands_disabled + $module->num_events_disabled;
			if ($numEnabled > 0 && $numDisabled > 0) {
				$a = "<yellow>Partial<end>";
			} elseif ($numDisabled === 0) {
				$a = "<green>Running<end>";
			} else {
				$a = "<red>Disabled<end>";
			}

			$c = "[" . $this->text->makeChatcmd("configure", "/tell <myname> config $module->name") . "]";

			$on = "<black>[ON]<end>";
			if ($numDisabled > 0) {
				$on = "[" . $this->text->makeChatcmd("ON", "/tell <myname> config mod $module->name enable all") . "]";
			}
			$off = "<black>[OFF]<end>";
			if ($numEnabled > 0) {
				$off = "[" . $this->text->makeChatcmd("OFF", "/tell <myname> config mod $module->name disable all") . "]";
			}
			$blob .= "$on $off $c " . strtoupper($module->name) . " ($a)\n";
		}

		$count = count($modules);
		$msg = $this->text->makeBlob("Module Config ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * This command handler turns a channel of all modules on or off.
	 */
	#[NCA\HandlesCommand("config")]
	public function toggleChannelOfAllModulesCommand(
		CmdContext $context,
		#[NCA\Str("cmd")] string $cmd,
		bool $status,
		#[NCA\Regexp("all|guild|priv|msg")] string $channel
	): void {
		$updQuery = $this->db->table(CommandManager::DB_TABLE)
			->whereIn("cmdevent", ["cmd", "subcmd"])
			->where("cmd", "!=", "config");
		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmdevent", "cmd")
			->where("cmd", "!=", "config");
		$confirmString = "all";
		if ($channel === "all") {
			$query->whereIn("type", ["guild", "priv", "msg"]);
			$updQuery->whereIn("type", ["guild", "priv", "msg"]);
		} else {
			$query->where("type", $channel);
			$updQuery->where("type", $channel);
			$confirmString = "all " . $channel;
		}

		$data = $query->asObj(CmdCfg::class)->toArray();

		foreach ($data as $row) {
			if (!$this->accessManager->checkAccess($context->char->name, $row->admin)) {
				continue;
			}
			if ($status) {
				$this->commandManager->activate($row->type, $row->file, $row->cmd, $row->admin);
			} else {
				$this->commandManager->deactivate($row->type, $row->file, $row->cmd);
			}
		}

		$updQuery->update(["status" => (int)$status]);

		$msg = "Successfully <highlight>" . ($status ? "enabled" : "disabled") . "<end> $confirmString commands.";
		$context->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single module on or off
	 */
	#[NCA\HandlesCommand("config")]
	public function toggleModuleChannelCommand(
		CmdContext $context,
		#[NCA\Str("mod")] string $action,
		string $module,
		bool $enable,
		#[NCA\Regexp("priv|msg|guild|all")] string $channel
	): void {
		$channel = strtolower($channel);
		if (!$this->toggleModule($module, $channel, $enable)) {
			if ($channel === "all") {
				$msg = "Could not find Module <highlight>{$module}<end>.";
			} else {
				$msg = "Could not find module <highlight>{$module}<end> for channel <highlight>{$channel}<end>.";
			}
			$context->reply($msg);
			return;
		}
		$color = $enable ? "green" : "red";
		$status = $enable ? "enable" : "disable";
		if ($channel === "all") {
			$msg = "Updated status of module <highlight>{$module}<end> to <{$color}>{$status}d<end>.";
		} else {
			$msg = "Updated status of module <highlight>{$module}<end> in channel <highlight>{$channel}<end> to <{$color}>{$status}d<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single command on or off
	 */
	#[NCA\HandlesCommand("config")]
	public function toggleCommandChannelCommand(
		CmdContext $context,
		#[NCA\Regexp("cmd|subcmd")] string $type,
		string $cmd,
		bool $enable,
		#[NCA\Regexp("priv|msg|guild|all")] string $channel
	): void {
		$type = strtolower($type);
		$channel = strtolower($channel);
		try {
			$result = $this->toggleCmd(
				$context->char->name,
				$type === "subcmd",
				$cmd,
				$channel,
				$enable
			);
		} catch (InsufficientAccessException $e) {
			$context->reply($e->getMessage());
			return;
		}
		$type = str_replace("cmd", "command", $type);
		if (!$result) {
			if ($channel !== "all") {
				$msg = "Could not find {$type} <highlight>{$cmd}<end> ".
					"for channel <highlight>{$channel}<end>.";
			} else {
				$msg = "Could not find {$type} <highlight>{$cmd}<end>.";
			}
			$context->reply($msg);
			return;
		}
		$color = $enable ? "green" : "red";
		$status = $enable ? "enable" : "disable";
		if ($channel === "all") {
			$msg = "Updated status of {$type} <highlight>{$cmd}<end> ".
				"to <{$color}>{$status}d<end>.";
		} else {
			$msg = "Updated status of {$type} <highlight>{$cmd}<end> ".
				"to <{$color}>{$status}d<end> in channel <highlight>{$channel}<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single event on or off
	 */
	#[NCA\HandlesCommand("config")]
	public function toggleEventCommand(
		CmdContext $context,
		#[NCA\Str("event")] string $type,
		string $event,
		bool $enable,
		#[NCA\Regexp("priv|msg|guild|all")] string $channel
	): void {
		$channel = strtolower($channel);
		$temp = explode(" ", $event);
		$eventType = strtolower($temp[0]);
		$file = $temp[1] ?? "";

		if ( !$this->toggleEvent($eventType, $file, $enable) ) {
			$msg = "Could not find event <highlight>{$eventType}<end> for handler <highlight>{$file}<end>.";
			$context->reply($msg);
			return;
		}

		$color = $enable ? "green" : "red";
		$status = $enable ? "enable" : "disable";
		$msg = "Updated status of event <highlight>{$eventType}<end> to <{$color}>{$status}d<end>.";

		$context->reply($msg);
	}

	/**
	 * Enable or disable a command or subcommand for one or all channels
	 */
	public function toggleCmd(string $sender, bool $subCmd, string $cmd, string $type, bool $enable): bool {
		$cmdEvent = $subCmd ? "subcmd" : "cmd";
		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmd", $cmd)
			->where("cmd", "!=", "config")
			->where("cmdevent", $cmdEvent);
		if ($type !== "all") {
			$query->where("type", $type);
		}

		/** @var CmdCfg[] $data */
		$data = $query->asObj(CmdCfg::class)->toArray();

		if (!$this->checkCommandAccessLevels($data, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this command.");
		}

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}
		$query->update(["status" => (int)$enable]);

		// for subcommands which are handled differently
		$this->subcommandManager->loadSubcommands();
		return true;
	}

	/**
	 * Enable or disable an event
	 */
	public function toggleEvent(string $eventType, string $file, bool $enable): bool {
		if ($file === "") {
			return false;
		}
		$query = $this->db->table(EventManager::DB_TABLE)
			->where("file", $file)
			->where("type", $eventType)
			->where("type", "!=", "setup")
			->select("*");
		$query->selectRaw($query->grammar->quoteString("event") . $query->as("cmdevent"));
		/** @var CmdCfg[] $data */
		$data = $query->asObj(CmdCfg::class)->toArray();

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}

		$query->update(["status" => (int)$enable]);

		return true;
	}

	/**
	 * Enable or disable all commands and events for a module
	 * @param string $module Name of the module
	 * @param string $channel msg, priv, guild or all
	 * @param bool $enable true for enabling, false for disabling
	 * @return bool True for success, False if the module doesn't exist
	 */
	public function toggleModule(string $module, string $channel, bool $enable): bool {
		$cmdQuery = $this->db->table(CommandManager::DB_TABLE)
			->where("module", $module)
			->where("cmd", "!=", "config")
			->select("status", "type", "file", "cmd", "admin", "cmdevent");
		$eventQuery = $this->db->table(EventManager::DB_TABLE)
			->where("module", $module)
			->where("type", "!=", "setup")
			->select("status", "type", "file");
		$eventQuery->selectRaw($eventQuery->grammar->quoteString('') . $eventQuery->as("cmd"));
		$eventQuery->selectRaw($eventQuery->grammar->quoteString('') . $eventQuery->as("admin"));
		$eventQuery->selectRaw($eventQuery->grammar->quoteString('event') . $eventQuery->as("cmdevent"));
		if ($channel !== "all") {
			$cmdQuery->where("type", $channel);
			$eventQuery->where("type", $channel);
		}

		$query = clone $cmdQuery;
		/** @var CmdCfg[] $data */
		$data = $query->union(clone $eventQuery)->asObj(CmdCfg::class)->toArray();

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}

		$cmdQuery->update(["status" => (int)$enable]);
		$eventQuery->update(["status" => (int)$enable]);

		// for subcommands which are handled differently
		$this->subcommandManager->loadSubcommands();
		return true;
	}

	public function toggleCmdCfg(CmdCfg $cfg, bool $enable): void {
		if ((bool)$cfg->status === $enable) {
			return;
		}
		if ($cfg->cmdevent === "event") {
			if ($cfg->verify !== 0) {
				if ($enable) {
					$this->eventManager->activate($cfg->type, $cfg->file);
				} else {
					$this->eventManager->deactivate($cfg->type, $cfg->file);
				}
			}
		} elseif ($cfg->cmdevent === "cmd") {
			if ($enable) {
				$this->commandManager->activate($cfg->type, $cfg->file, $cfg->cmd, $cfg->admin);
			} else {
				$this->commandManager->deactivate($cfg->type, $cfg->file, $cfg->cmd);
			}
		}
	}

	/**
	 * This command handler sets command's access level on a particular channel.
	 * Note: This handler has not been not registered, only activated.
	 */
	#[NCA\HandlesCommand("config")]
	public function setAccessLevelOfChannelCommand(
		CmdContext $context,
		#[NCA\Regexp("subcmd|cmd")] string $category,
		string $cmd,
		#[NCA\Str("admin")] string $admin,
		#[NCA\Regexp("msg|priv|guild|all")] string $channel,
		string $accessLevel
	): void {
		$category = strtolower($category);
		$command = strtolower($cmd);
		$channel = strtolower($channel);

		$type = "command";
		try {
			if ($category === "cmd") {
				$result = $this->changeCommandAL($context->char->name, $command, $channel, $accessLevel);
			} else {
				$type = "subcommand";
				$result = $this->changeSubcommandAL($context->char->name, $command, $channel, $accessLevel);
			}
		} catch (InsufficientAccessException $e) {
			$msg = "You do not have the required access level to change this {$type}.";
			$context->reply($msg);
			return;
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}

		if ($result === 0) {
			if ($channel === "all") {
				$msg = "Could not find {$type} <highlight>{$command}<end>.";
			} else {
				$msg = "Could not find {$type} <highlight>{$command}<end> for channel <highlight>{$channel}<end>.";
			}
			$context->reply($msg);
			return;
		}
		if ($result === -1) {
			$msg = "You may not set the access level for a {$type} above your own access level.";
			$context->reply($msg);
			return;
		}
		if ($channel === "all") {
			$msg = "Updated access of {$type} <highlight>{$command}<end> to <highlight>{$accessLevel}<end>.";
		} else {
			$msg = "Updated access of {$type} <highlight>{$command}<end> in channel <highlight>{$channel}<end> to <highlight>{$accessLevel}<end>.";
		}
		$context->reply($msg);
	}

	public function changeCommandAL(string $sender, string $command, string $channel, string $accessLevel): int {
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmd", $command)
			->where("cmdevent", "cmd");
		if ($channel !== "all") {
			$query->where("type", $channel);
		}
		/** @var CmdCfg[] $data */
		$data = $query->asObj(CmdCfg::class)->toArray();

		if (count($data) === 0) {
			return 0;
		} elseif (!$this->checkCommandAccessLevels($data, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this command.");
		} elseif (!$this->accessManager->checkAccess($sender, $accessLevel)) {
			return -1;
		}
		$this->commandManager->updateStatus($channel, $command, null, 1, $accessLevel);
		return 1;
	}

	public function changeSubcommandAL(string $sender, string $command, string $channel, string $accessLevel): int {
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("type", $channel)
			->where("cmdevent", "subcmd")
			->where("cmd", $command);
		/** @var CmdCfg[] $data */
		$data = $query->asObj(CmdCfg::class)->toArray();
		if (count($data) === 0) {
			return 0;
		} elseif (!$this->checkCommandAccessLevels($data, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this subcommand.");
		} elseif (!$this->accessManager->checkAccess($sender, $accessLevel)) {
			return -1;
		}
		$query->update(["admin" => $accessLevel]);
		$this->subcommandManager->loadSubcommands();
		return 1;
	}

	/**
	 * Check if sender has access to all commands in $data
	 * @param CmdCfg[] $data
	 * @param string $sender
	 * @return bool
	 */
	public function checkCommandAccessLevels(array $data, string $sender): bool {
		foreach ($data as $row) {
			if (!$this->accessManager->checkAccess($sender, $row->admin)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * This command handler shows information and controls of a command in
	 * each channel.
	 */
	#[NCA\HandlesCommand("config")]
	public function configCommandCommand(CmdContext $context, #[NCA\Str("cmd")] string $action, PWord $cmd): void {
		$cmd = strtolower($cmd());

		$aliasCmd = $this->commandAlias->getBaseCommandForAlias($cmd);
		if ($aliasCmd !== null) {
			$cmd = $aliasCmd;
		}

		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmd", $cmd)
			->where("cmd", "!=", "config");
		if (!$query->exists()) {
			$msg = "Could not find command <highlight>{$cmd}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = '';

		$blob .= "<header2>Tells:<end> ";
		$blob .= $this->getCommandInfo($cmd, 'msg');
		$blob .= "\n\n";

		$blob .= "<header2>Private Channel:<end> ";
		$blob .= $this->getCommandInfo($cmd, 'priv');
		$blob .= "\n\n";

		$blob .= "<header2>Guild Channel:<end> ";
		$blob .= $this->getCommandInfo($cmd, 'guild');
		$blob .= "\n\n";

		$subcmd_list = '';
		$output = $this->getSubCommandInfo($cmd, 'msg');
		if ($output) {
			$subcmd_list .= "<header>Available Subcommands in tells<end>\n\n";
			$subcmd_list .= $output;
		}

		$output = $this->getSubCommandInfo($cmd, 'priv');
		if ($output) {
			$subcmd_list .= "<header>Available Subcommands in Private Channel<end>\n\n";
			$subcmd_list .= $output;
		}

		$output = $this->getSubCommandInfo($cmd, 'guild');
		if ($output) {
			$subcmd_list .= "<header>Available Subcommands in Guild Channel<end>\n\n";
			$subcmd_list .= $output;
		}

		if ($subcmd_list) {
			$blob .= $subcmd_list;
		}

		$help = $this->helpManager->find($cmd, $context->char->name);
		if ($help !== null) {
			$blob .= "<header>Help ($cmd)<end>\n\n" . $help;
		}

		$msg = $this->text->makeBlob(ucfirst($cmd)." Config", $blob);
		$context->reply($msg);
	}

	/**
	 * Get a blob like "Aliases: alias1, alias2" for command $cmd
	 */
	public function getAliasInfo(string $cmd): string {
		$aliases = $this->commandAlias->findAliasesByCommand($cmd);
		$aliasesList = [];
		foreach ($aliases as $row) {
			if ($row->status === 1) {
				$aliasesList []= "<highlight>{$row->alias}<end>";
			}
		}
		$aliasesBlob = join(", ", $aliasesList);

		$blob = '';
		if (count($aliasesList) > 0) {
			$blob .= "Aliases: $aliasesBlob\n\n";
		}
		return $blob;
	}

	public function getModuleDescription(string $module): ?string {
		$module = strtoupper($module);
		$path = $this->chatBot->runner->classLoader->registeredModules[$module] ?? null;
		if (!isset($path)) {
			return null;
		}
		$files = array_values(array_filter(
			glob("{$path}/*", GLOB_NOSORT) ?: [],
			function (string $file): bool {
				return strtolower(basename($file)) === "readme.txt";
			}
		));
		if (!count($files)) {
			return null;
		}
		return trim(file_get_contents($files[0]));
	}


	/**
	 * This command handler shows configuration and controls for a single module.
	 */
	#[NCA\HandlesCommand("config")]
	public function configModuleCommand(CmdContext $context, PWord $module): void {
		$module = strtoupper($module());
		$found = false;

		$on = $this->text->makeChatcmd("enable", "/tell <myname> config mod {$module} enable all");
		$off = $this->text->makeChatcmd("disable", "/tell <myname> config mod {$module} disable all");

		$blob = "Enable/disable entire module: [$on] [$off]\n";
		$description = $this->getModuleDescription($module);
		if (isset($description)) {
			$description = implode("<br><tab>", explode("\n", $description));
			$description = preg_replace_callback(
				"/(https?:\/\/[^\s\n<]+)/s",
				function(array $matches): string {
					return $this->text->makeChatcmd($matches[1], "/start {$matches[1]}");
				},
				$description
			);
			$blob .= "\n<header2>Description<end>\n<tab>{$description}\n";
		}

		$data = $this->getModuleSettings($module);
		if (count($data) > 0) {
			$found = true;
			$blob .= "\n<header2>Settings<end>\n";
		}

		foreach ($data as $row) {
			$blob .= "<tab>" . ($row->getData()->description ?? "");

			if ($row->isEditable() && $this->accessManager->checkAccess($context->char->name, $row->getData()->admin??"superadmin")) {
				$blob .= " [" . $this->text->makeChatcmd("modify", "/tell <myname> settings change " . $row->getData()->name) . "]";
			}

			$blob .= ": " . $row->displayValue($context->char->name) . "\n";
		}

		$data = $this->getAllRegisteredCommands($module);
		if (count($data) > 0) {
			$found = true;
			$blob .= "\n<header2>Commands<end>\n";
			usort($data, function (RegisteredCmd $a, RegisteredCmd $b): int {
				return strcmp($a->cmd, $b->cmd);
			});
		}
		foreach ($data as $row) {
			$guild = '';
			$priv = '';
			$msg = '';

			$cmdNameLink = "";
			if ($row->cmdevent === 'cmd') {
				$on = $this->text->makeChatcmd("ON", "/tell <myname> config cmd $row->cmd enable all");
				$off = $this->text->makeChatcmd("OFF", "/tell <myname> config cmd $row->cmd disable all");
				$cmdNameLink = $this->text->makeChatcmd($row->cmd, "/tell <myname> config cmd $row->cmd");
			} elseif ($row->cmdevent === 'subcmd') {
				$on = $this->text->makeChatcmd("ON", "/tell <myname> config subcmd $row->cmd enable all");
				$off = $this->text->makeChatcmd("OFF", "/tell <myname> config subcmd $row->cmd disable all");
				$cmdNameLink = "<tab>{$row->cmd}";
			}

			$tell = "<red>T<end>";
			if ($row->msg_avail == 0) {
				$tell = "|_";
			} elseif ($row->msg_status === 1) {
				$tell = "<green>T<end>";
			}

			$guild = "|<red>G<end>";
			if ($row->guild_avail === 0) {
				$guild = "|_";
			} elseif ($row->guild_status === 1) {
				$guild = "|<green>G<end>";
			}

			$priv = "|<red>P<end>";
			if ($row->priv_avail === 0) {
				$priv = "|_";
			} elseif ($row->priv_status === 1) {
				$priv = "|<green>P<end>";
			}

			if ($row->description !== null && $row->description !== "") {
				$blob .= "<tab>$cmdNameLink ($tell$guild$priv): $on  $off - ($row->description)\n";
			} else {
				$blob .= "<tab>$cmdNameLink - ($tell$guild$priv): $on  $off\n";
			}
		}

		/** @var EventCfg[] */
		$data = $this->db->table(EventManager::DB_TABLE)
			->where("type", "!=", "setup")
			->where("module", $module)
			->orderBy("type")
			->asObj(EventCfg::class)
			->toArray();
		if (count($data) > 0) {
			$found = true;
			$blob .= "\n<header2>Events<end>\n";
		}
		foreach ($data as $row) {
			$on = $this->text->makeChatcmd("ON", "/tell <myname> config event ".$row->type." ".$row->file." enable all");
			$off = $this->text->makeChatcmd("OFF", "/tell <myname> config event ".$row->type." ".$row->file." disable all");

			if ($row->status == 1) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			if ($row->description !== null && $row->description !== "none") {
				$blob .= "<tab><highlight>$row->type<end> ($row->description) - ($status): $on  $off \n";
			} else {
				$blob .= "<tab><highlight>$row->type<end> - ($status): $on  $off \n";
			}
		}

		if ($found) {
			$msg = $this->text->makeBlob("$module Configuration", $blob);
		} else {
			$msg = "Could not find module <highlight>$module<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * This helper method converts given short access level name to long name.
	 */
	private function getAdminDescription(string $admin): string {
		$desc = $this->accessManager->getDisplayName($admin);
		return ucfirst(strtolower($desc));
	}

	/**
	 * This helper method builds information and controls for given command.
	 */
	private function getCommandInfo(string $cmd, string $type): string {
		$msg = "";
		/** @var CmdCfg[] $data */
		$data = $this->db->table(CommandManager::DB_TABLE)
			->where("cmd", $cmd)
			->where("type", $type)
			->asObj(CmdCfg::class)
			->toArray();
		if (count($data) == 0) {
			$msg .= "<red>Unused<end>\n";
		} elseif (count($data) > 1) {
			$this->logger->error("Multiple rows exists for cmd: '$cmd' and type: '$type'");
			return $msg;
		}
		$row = $data[0];

		$row->admin = $this->getAdminDescription($row->admin);

		if ($row->status === 1) {
			$status = "<green>Enabled<end>";
		} else {
			$status = "<red>Disabled<end>";
		}

		$msg .= "$status (Access: $row->admin) \n";
		$msg .= "Set status: [";
		$msg .= $this->text->makeChatcmd("enabled", "/tell <myname> config cmd {$cmd} enable {$type}") . "] [";
		$msg .= $this->text->makeChatcmd("disabled", "/tell <myname> config cmd {$cmd} disable {$type}") . "]\n";

		$msg .= "Set access level: ";
		$showRaidAL = $this->db->table(CommandManager::DB_TABLE)
			->where("module", "RAID_MODULE")
			->where("status", 1)
			->exists();
		foreach ($this->accessManager->getAccessLevels() as $accessLevel => $level) {
			if ($accessLevel === 'none') {
				continue;
			}
			if (substr($accessLevel, 0, 5) === "raid_" && !$showRaidAL) {
				continue;
			}
			$alName = $this->getAdminDescription($accessLevel);
			$msg .= $this->text->makeChatcmd("{$alName}", "/tell <myname> config cmd {$cmd} admin {$type} $accessLevel") . "  ";
		}
		$msg .= "\n";
		return $msg;
	}

	/**
	 * This helper method builds information and controls for given subcommand.
	 */
	private function getSubCommandInfo(string $cmd, string $type): string {
		$subcmd_list = '';
		/** @var CmdCfg[] $data */
		$data = $this->db->table(CommandManager::DB_TABLE)
			->where("dependson", $cmd)
			->where("type", $type)
			->where("cmdevent", "subcmd")
			->asObj(CmdCfg::class)
			->toArray();
		$showRaidAL = $this->db->table(CommandManager::DB_TABLE)
			->where("module", "RAID_MODULE")
			->where("status", 1)
			->exists();
		foreach ($data as $row) {
			$subcmd_list .= "<pagebreak><header2>$row->cmd<end> ($type)\n";
			if ($row->description != "") {
				$subcmd_list .= "<tab>Description: <highlight>$row->description<end>\n";
			}

			$row->admin = $this->getAdminDescription($row->admin);

			if ($row->status == 1) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			$subcmd_list .= "<tab>Current Status: $status (Access: $row->admin) \n";
			$subcmd_list .= "<tab>Set status: [";
			$subcmd_list .= $this->text->makeChatcmd("enabled", "/tell <myname> config subcmd {$row->cmd} enable {$type}") . "] [";
			$subcmd_list .= $this->text->makeChatcmd("disabled", "/tell <myname> config subcmd {$row->cmd} disable {$type}") . "]\n";

			$subcmd_list .= "<tab>Set access level: ";
			foreach ($this->accessManager->getAccessLevels() as $accessLevel => $level) {
				if ($accessLevel == 'none') {
					continue;
				}
				if (substr($accessLevel, 0, 5) === "raid_" && !$showRaidAL) {
					continue;
				}
				$alName = $this->getAdminDescription($accessLevel);
				$subcmd_list .= $this->text->makeChatcmd($alName, "/tell <myname> config subcmd {$row->cmd} admin {$type} $accessLevel") . "  ";
			}
			$subcmd_list .= "\n\n";
		}
		return $subcmd_list;
	}

	/**
	 * Get a list of all installed modules and some stats regarding the settings
	 * @return ConfigModule[]
	 */
	public function getModules(): array {
		$cmdQuery = $this->db->table(CommandManager::DB_TABLE)
			->where("cmdevent", "cmd")
			->select("module", "status");
		$eventQuery = $this->db->table(EventManager::DB_TABLE)
			->select("module");
		$eventQuery->selectRaw($eventQuery->grammar->wrap("status") . "+2");
		$settingsQuery = $this->db->table(SettingManager::DB_TABLE)
			->select("module");
		$settingsQuery->selectRaw("4");

		$outerQuery = $this->db->fromSub(
			$cmdQuery->unionAll($eventQuery)->unionAll($settingsQuery),
			"t"
		)->groupBy("t.module")
		->orderBy("module")
		->select("t.module");
		$stat = $outerQuery->grammar->wrap("status");
		$outerQuery->selectRaw(
			"SUM(CASE WHEN {$stat} = 0 then 1 ELSE 0 END)".
			$outerQuery->as("count_cmd_disabled")
		);
		$outerQuery->selectRaw(
			"SUM(CASE WHEN {$stat} = 1 then 1 ELSE 0 END)".
			$outerQuery->as("count_cmd_enabled")
		);
		$outerQuery->selectRaw(
			"SUM(CASE WHEN {$stat} = 2 then 1 ELSE 0 END)".
			$outerQuery->as("count_events_disabled")
		);
		$outerQuery->selectRaw(
			"SUM(CASE WHEN {$stat} = 3 then 1 ELSE 0 END)".
			$outerQuery->as("count_events_enabled")
		);
		$outerQuery->selectRaw(
			"SUM(CASE WHEN {$stat} = 4 then 1 ELSE 0 END)".
			$outerQuery->as("count_settings")
		);
		/** @var Collection<ModuleStats> */
		$data = $outerQuery->asObj(ModuleStats::class);
		$result = [];
		foreach ($data as $row) {
			$config = new ConfigModule();
			$config->name = $row->module;
			$config->description = $this->getModuleDescription($config->name);
			$config->num_commands_enabled = (int)$row->count_cmd_enabled;
			$config->num_commands_disabled = (int)$row->count_cmd_disabled;
			$config->num_events_disabled = (int)$row->count_events_disabled;
			$config->num_events_enabled = (int)$row->count_events_enabled;
			$config->num_settings = (int)$row->count_settings;
			$result []= $config;
		}
		return $result;
	}

	/**
	 * Get all accesslevels, their name, full name and numeric value
	 * @return ModuleAccessLevel[]
	 */
	public function getValidAccessLevels(): array {
		$showRaidAL = $this->db->table(CommandManager::DB_TABLE)
			->where("module", "RAID_MODULE")
			->where("status", 1)
			->exists();
		$result = [];
		foreach ($this->accessManager->getAccessLevels() as $accessLevel => $level) {
			if ($accessLevel == 'none') {
				continue;
			}
			$option = new ModuleAccessLevel();
			$option->name = $this->getAdminDescription($accessLevel);
			$option->value = $accessLevel;
			$option->numeric_value = $level;
			if (substr($accessLevel, 0, 5) === "raid_" && !$showRaidAL) {
				$option->enabled = false;
			}
			$result []= $option;
		}
		return $result;
	}

	/**
	 * Get all settings for a module
	 * @return SettingHandler[]
	 */
	public function getModuleSettings(string $module): array {
		$module = strtoupper($module);

		return $this->db->table(SettingManager::DB_TABLE)
			->where("module", $module)
			->orderBy("mode")
			->orderBy("description")
			->asObj(Setting::class)
			->map(function (Setting $setting): ?SettingHandler {
				return $this->settingManager->getSettingHandler($setting);
			})
			->filter()
			->toArray();
	}

	protected function getRegisteredCommandsQuery(): QueryBuilder {
		$query = $this->db->table(CommandManager::DB_TABLE)
			->whereIn("cmdevent", ["cmd", "subcmd"])
			->groupBy("module", "cmdevent", "file", "cmd", "description", "verify", "dependson", "help")
			->select("module", "cmdevent", "file", "cmd", "description", "verify", "dependson", "help");
		$query->addSelect($query->colFunc("MAX", "status", "status"));
		$type = $query->grammar->wrap("type");
		$status = $query->grammar->wrap("status");
		$admin = $query->grammar->wrap("admin");
		foreach (["guild", "priv", "msg"] as $channel) {
			$qChannel = $query->grammar->quoteString($channel);
			$query->selectRaw(
				"SUM(CASE WHEN {$type} = {$qChannel} THEN 1 ELSE 0 END)".
				$query->as("{$channel}_avail")
			);
			$query->selectRaw(
				"SUM(CASE WHEN {$type} = {$qChannel} AND {$status} = 1 THEN 1 ELSE 0 END)".
				$query->as("{$channel}_status")
			);
			$query->selectRaw(
				"MAX(CASE WHEN {$type} = {$qChannel} THEN {$admin} ELSE null END)".
				$query->as("{$channel}_al")
			);
		}
		return $query;
	}

	/**
	 * @return RegisteredCmd[]
	 */
	public function getAllRegisteredCommands(string $module): array {
		$query = $this->getRegisteredCommandsQuery();
		$query->where("module", $module);
		$query->where("cmd", "!=", "config");
		return $query->asObj(RegisteredCmd::class)->toArray();
	}

	public function getRegisteredCommand(string $module, string $command): ?RegisteredCmd {
		$query = $this->getRegisteredCommandsQuery();
		$query->where("module", $module);
		$query->where("cmd", $command);
		$query->where("cmd", "!=", "config");
		return $query->asObj(RegisteredCmd::class)->first();
	}
}
