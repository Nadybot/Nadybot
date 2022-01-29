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
	ParamClass\PWord,
	Registry,
	SettingHandler,
	SettingManager,
	SubcommandManager,
	Text,
};
use Nadybot\Core\DBSchema\{
	EventCfg,
	CmdCfg,
	CmdPermission,
	CmdPermissionSet,
	Setting,
};

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

		foreach ($this->commandManager->getPermissionSets() as $set) {
			$this->commandManager->activate($set->name, $filename, "config", "mod");
		}

		$this->helpManager->register($this->moduleName, "config", "config.txt", "mod", "Configure Commands/Events");
	}

	/**
	 * This command handler lists list of modules which can be configured.
	 */
	#[NCA\HandlesCommand("config")]
	public function configCommand(CmdContext $context): void {
		$channels = $this->commandManager->getPermissionSets();
		$blob = "<header2>Quick config<end>\n".
			$channels->map(function (CmdPermissionSet $set): string {
				return "<tab>" . ucfirst(strtolower($set->name)) . " Commands [" .
					$this->text->makeChatcmd('enable all', '/tell <myname> config cmd enable ' . $set->name) . "] [" .
					$this->text->makeChatcmd('disable all', '/tell <myname> config cmd disable ' . $set->name) . "]";
			})->join("\n").
			"\n<tab>ALL Commands [" .
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
		PWord $permissionSet
	): void {
		$permissionSet = strtolower($permissionSet());
		if ($permissionSet !== "all" && !$this->commandManager->hasPermissionSet($permissionSet)) {
			$context->reply("No such channel '<highlight>{$permissionSet}<end>'.");
			return;
		}
		$permQuery = $this->db->table(CommandManager::DB_TABLE_PERMS);
		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("cmdevent", "cmd")
			->where("cmd", "!=", "config");
		$confirmString = "all";
		if ($permissionSet !== "all") {
			$permQuery->where("permission_set", $permissionSet);
			$confirmString = "all " . $permissionSet;
		}

		/** @var Collection<CmdCfg> */
		$data = $query->asObj(CmdCfg::class);
		$permissions = $permQuery->whereIn("cmd", $data->pluck("cmd")->toArray())
			->asObj(CmdPermission::class)
			->groupBy("cmd");
		$updated = [];

		foreach ($data as $row) {
			/** @var Collection<CmdPermission> */
			$cmdPerms = $permissions->get($row->cmd, new Collection());
			foreach ($cmdPerms as $perm) {
				if (!$this->accessManager->checkAccess($context->char->name, $perm->access_level)) {
					continue;
				}
				$updated []= $perm->id;
				if ($status) {
					$this->commandManager->activate($perm->permission_set, $row->file, $row->cmd, $perm->access_level);
				} else {
					$this->commandManager->deactivate($perm->permission_set, $row->file, $row->cmd);
				}
			}
		}

		$this->db->table(CommandManager::DB_TABLE_PERMS)
			->whereIn("id", $updated)
			->update(["enabled" => $status]);

		$msg = "Successfully " . ($status ? "<green>enabled" : "<red>disabled") . "<end> $confirmString commands.";
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
		PWord $channel
	): void {
		$channel = strtolower($channel());
		if ($channel !== "all" && !$this->commandManager->hasPermissionSet($channel)) {
			$context->reply("No such channel '<highlight>{$channel}<end>'.");
			return;
		}
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
		PWord $channel
	): void {
		$type = strtolower($type);
		$channel = strtolower($channel());
		if ($channel !== "all" && !$this->commandManager->hasPermissionSet($channel)) {
			$context->reply("No such channel '<highlight>{$channel}<end>'.");
			return;
		}
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
		PWord $channel
	): void {
		$channel = strtolower($channel());
		if ($channel !== "all" && !$this->commandManager->hasPermissionSet($channel)) {
			$context->reply("No such channel '<highlight>{$channel}<end>'.");
			return;
		}
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
	public function toggleCmd(string $sender, bool $subCmd, string $cmd, string $channel, bool $enable): bool {
		$cmdEvent = $subCmd ? "subcmd" : "cmd";
		$cfg = $this->commandManager->get($cmd, ($channel === "all") ? null : $channel);
		if (!isset($cfg) || $cmd === "config" || $cfg->cmdevent !== $cmdEvent) {
			return false;
		}

		if (!$this->checkCommandAccessLevels($cfg, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this command.");
		}

		$this->toggleCmdCfg($cfg, $enable);
		$this->db->table(CommandManager::DB_TABLE_PERMS)
			->whereIn("id", array_column($cfg->permissions, "id"))
			->update(["enabled" => $enable]);

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
			->select("*");
		/** @var Collection<EventCfg> */
		$data = $query->asObj(EventCfg::class);
		$data->each(function (EventCfg $cfg) use ($enable): void {
			$this->toggleEventCfg($cfg, $enable);
		});

		$query->update(["status" => (int)$enable]);

		return true;
	}

	/**
	 * Enable or disable all commands and events for a module
	 * @param string $module Name of the module
	 * @param string $permissionSet msg, priv, guild or all
	 * @param bool $enable true for enabling, false for disabling
	 * @return bool True for success, False if the module doesn't exist
	 */
	public function toggleModule(string $module, string $permissionSet, bool $enable): bool {
		$commands = $this->commandManager->getAllForModule($module, true)
			->where("cmd", "!=", "config");
		$events = new Collection();
		if ($permissionSet === "all") {
			$eventQuery = $this->db->table(EventManager::DB_TABLE)
				->where("module", $module);
			$events = $eventQuery->asObj(EventCfg::class);
		}
		if ($permissionSet !== "all") {
			$commands = $commands->filter(function (CmdCfg $cfg) use ($permissionSet): bool {
				$cfg->permissions = (new Collection($cfg->permissions))
					->where("permission_set", $permissionSet)->toArray();
				if (empty($cfg->permissions)) {
					return false;
				}
				return true;
			});
		}

		$ids = [];
		foreach ($commands as $cmd) {
			$ids = array_merge($ids, array_column($cmd->permissions, "id"));
			$this->toggleCmdCfg($cmd, $enable);
		}
		foreach ($events as $event) {
			$this->toggleEventCfg($event, $enable);
		}

		$this->db->table(CommandManager::DB_TABLE_PERMS)
			->whereIn("id", $ids)
			->update(["enabled" => $enable]);
		if ($events->isNotEmpty() && isset($eventQuery)) {
			$eventQuery->update(["status" => (int)$enable]);
		}

		// for subcommands which are handled differently
		$this->subcommandManager->loadSubcommands();
		return true;
	}

	public function toggleCmdCfg(CmdCfg $cfg, bool $enable): void {
		foreach ($cfg->permissions as $perm) {
			if ($perm->enabled === $enable) {
				continue;
			}
			if ($cfg->cmdevent === "cmd") {
				if ($enable) {
					$this->commandManager->activate($perm->permission_set, $cfg->file, $cfg->cmd, $perm->access_level);
				} else {
					$this->commandManager->deactivate($perm->permission_set, $cfg->file, $cfg->cmd);
				}
			}
		}
	}

	public function toggleEventCfg(EventCfg $cfg, bool $enable): void {
		if ((bool)$cfg->status === $enable) {
			return;
		}
		if ($cfg->verify !== 0) {
			if ($enable) {
				$this->eventManager->activate($cfg->type, $cfg->file);
			} else {
				$this->eventManager->deactivate($cfg->type, $cfg->file);
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
		PWord $channel,
		string $accessLevel
	): void {
		$category = strtolower($category);
		$command = strtolower($cmd);
		$channel = strtolower($channel());
		if ($channel !== "all" && !$this->commandManager->hasPermissionSet($channel)) {
			$context->reply("No such channel '<highlight>{$channel}<end>'.");
			return;
		}

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

		$cfg = $this->commandManager->get($command, ($channel === "all") ? null : $channel);

		if (!isset($cfg)) {
			return 0;
		} elseif (!$this->checkCommandAccessLevels($cfg, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this command.");
		} elseif (!$this->accessManager->checkAccess($sender, $accessLevel)) {
			return -1;
		}
		$this->commandManager->updateStatus($channel, $command, null, 1, $accessLevel);
		return 1;
	}

	public function changeSubcommandAL(string $sender, string $command, string $channel, string $accessLevel): int {
		$cfg = $this->commandManager->get($command, $channel);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		if (!isset($cfg)) {
			return 0;
		} elseif (!$this->checkCommandAccessLevels($cfg, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this subcommand.");
		} elseif (!$this->accessManager->checkAccess($sender, $accessLevel)) {
			return -1;
		}
		$this->db->table(CommandManager::DB_TABLE_PERMS)
			->whereIn("id", array_column($cfg->permissions, "id"))
			->update(["access_level" => $accessLevel]);
		$this->subcommandManager->loadSubcommands();
		return 1;
	}

	/**
	 * Check if sender has access to all commands in $data
	 */
	public function checkCommandAccessLevels(CmdCfg $data, string $sender): bool {
		foreach ($data->permissions as $permission) {
			if (!$this->accessManager->checkAccess($sender, $permission->access_level)) {
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
	public function configCommandCommand(
		CmdContext $context,
		#[NCA\Str("cmd")] string $action,
		PWord $cmd
	): void {
		$cmd = strtolower($cmd());

		$aliasCmd = $this->commandAlias->getBaseCommandForAlias($cmd);
		if ($aliasCmd !== null) {
			$cmd = $aliasCmd;
		}

		$cfg = $this->commandManager->get($cmd);
		if (!isset($cfg) || $cmd === "config") {
			$msg = "Could not find command <highlight>{$cmd}<end>.";
			$context->reply($msg);
			return;
		}
		$permSets = $this->commandManager->getPermissionSets();
		$blob = '';
		foreach ($permSets as $permSet) {
			$blob .= "<header2>" . ucfirst(strtolower($permSet->name)) . "<end> ".
				$this->getCommandInfo($cmd, $permSet->name).
				"\n\n";
		}

		$subcmdList = '';
		foreach ($permSets as $permSet) {
			$output = $this->getSubCommandInfo($cmd, $permSet->name);
			if ($output) {
				$subcmdList .= "<header>Available Subcommands in {$permSet->name}<end>\n\n";
				$subcmdList .= $output;
			}
		}

		$blob .= $subcmdList;

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

		$data = $this->commandManager->getAll(true)->where("module", $module);
		if ($data->isNotEmpty()) {
			$found = true;
			$blob .= "\n<header2>Commands<end>\n";
			$data = $data->sort(function (CmdCfg $a, CmdCfg $b): int {
				return strcmp($a->cmd, $b->cmd);
			});
		}
		$permissionSets = $this->commandManager->getPermissionSets()->keyBy("name");
		foreach ($data as $row) {
			$cmdNameLink = "";
			$statusLinks = [];
			if ($row->cmdevent === 'cmd') {
				$enabled = array_column($row->permissions, "enabled");
				if (in_array(false, $enabled, true)) {
					$statusLinks []= $this->text->makeChatcmd("enable", "/tell <myname> config cmd {$row->cmd} enable all");
				}
				if (in_array(true, $enabled, true)) {
					$statusLinks []= $this->text->makeChatcmd("disable", "/tell <myname> config cmd {$row->cmd} disable all");
				}
				$cmdNameLink = $this->text->makeChatcmd($row->cmd, "/tell <myname> config cmd {$row->cmd}");
			} elseif ($row->cmdevent === 'subcmd') {
				$enabled = array_column($row->permissions, "enabled");
				if (in_array(false, $enabled, true)) {
					$statusLinks []= $this->text->makeChatcmd("enable", "/tell <myname> config subcmd {$row->cmd} enable all");
				}
				if (in_array(true, $enabled, true)) {
					$statusLinks []= $this->text->makeChatcmd("disable", "/tell <myname> config subcmd {$row->cmd} disable all");
				}
				$cmdNameLink = "<tab>{$row->cmd}";
			}

			$status = [];
			foreach ($permissionSets as $set) {
				if (!isset($row->permissions[$set->name])) {
					$status []= "_";
				} elseif ($row->permissions[$set->name]->enabled) {
					$status []= "<green>" . strtoupper($set->letter) . "<end>";
				} else {
					$status []= "<red>" . strtoupper($set->letter) . "<end>";
				}
			}

			$blob .= "<tab>$cmdNameLink (" . join("|", $status) . "): [" . join("] [", $statusLinks) . "]";
			if ($row->description !== null && $row->description !== "") {
				$blob .= " - ($row->description)\n";
			} else {
				$blob .= "\n";
			}
		}

		/** @var EventCfg[] */
		$data = $this->db->table(EventManager::DB_TABLE)
			->where("module", $module)
			->orderBy("type")
			->asObj(EventCfg::class)
			->toArray();
		if (count($data) > 0) {
			$found = true;
			$blob .= "\n<header2>Events<end>\n";
		}
		foreach ($data as $row) {
			if ($row->status) {
				$statusLink = $this->text->makeChatcmd("disable", "/tell <myname> config event ".$row->type." ".$row->file." disable all");
			} else {
				$statusLink = $this->text->makeChatcmd("enable", "/tell <myname> config event ".$row->type." ".$row->file." enable all");
			}

			if ($row->status == 1) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			if ($row->description !== null && $row->description !== "none") {
				$blob .= "<tab><highlight>$row->type<end> ($row->description) - ($status): [{$statusLink}]\n";
			} else {
				$blob .= "<tab><highlight>$row->type<end> - ($status): [{$statusLink}]\n";
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
	private function getCommandInfo(string $cmd, string $permSet): string {
		$msg = "";
		$cfg = $this->commandManager->get($cmd, $permSet);
		if (!isset($cfg) || !isset($cfg->permissions[$permSet])) {
			$msg .= "<red>Unused<end>\n";
		} else {
			$perm = $cfg->permissions[$permSet];

			$perm->access_level = $this->getAdminDescription($perm->access_level);

			if ($perm->enabled) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			$msg .= "$status (Access: $perm->access_level)\n";
		}
		$msg .= "Set status: [";
		$msg .= $this->text->makeChatcmd("enabled", "/tell <myname> config cmd {$cmd} enable {$permSet}") . "] [";
		$msg .= $this->text->makeChatcmd("disabled", "/tell <myname> config cmd {$cmd} disable {$permSet}") . "]\n";

		$msg .= "Set access level: ";
		$showRaidAL = $this->db->table(CommandManager::DB_TABLE, "c")
			->join(CommandManager::DB_TABLE_PERMS . " as p", "c.cmd", "p.cmd")
			->where("c.module", "RAID_MODULE")
			->where("p.enabled", true)
			->exists();
		foreach ($this->accessManager->getAccessLevels() as $accessLevel => $level) {
			if ($accessLevel === 'none') {
				continue;
			}
			if (substr($accessLevel, 0, 5) === "raid_" && !$showRaidAL) {
				continue;
			}
			$alName = $this->getAdminDescription($accessLevel);
			$msg .= $this->text->makeChatcmd("{$alName}", "/tell <myname> config cmd {$cmd} admin {$permSet} $accessLevel") . "  ";
		}
		$msg .= "\n";
		return $msg;
	}

	/**
	 * This helper method builds information and controls for given subcommand.
	 */
	private function getSubCommandInfo(string $cmd, string $permSet): string {
		$subcmdList = '';
		/** @var Collection<CmdCfg> */
		$commands = $this->db->table(CommandManager::DB_TABLE)
			->where("dependson", $cmd)
			->where("cmdevent", "subcmd")
			->asObj(CmdCfg::class);
		$permissions = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->where("permission_set", $permSet)
			->whereIn("cmd", $commands->pluck("cmd")->toArray())
			->asObj(CmdPermission::class)
			->groupBy("cmd");
		$commands->each(function (CmdCfg $row) use ($permissions): void {
			$row->permissions = $permissions->get($row->cmd, new Collection())
				->keyBy("permission_set")->toArray();
		});

		$showRaidAL = $this->db->table(CommandManager::DB_TABLE, "c")
			->join(CommandManager::DB_TABLE_PERMS . " as p", "c.cmd", "p.cmd")
			->where("c.module", "RAID_MODULE")
			->where("p.enabled", true)
			->exists();
		foreach ($commands as $command) {
			$perms = $command->permissions[$permSet] ?? null;
			if (!isset($perms)) {
				continue;
			}
			$subcmdList .= "<pagebreak><header2>{$command->cmd}<end> ({$permSet})\n";
			if ($command->description != "") {
				$subcmdList .= "<tab>Description: <highlight>{$command->description}<end>\n";
			}

			$perms->access_level = $this->getAdminDescription($perms->access_level);

			if ($perms->enabled) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			$subcmdList .= "<tab>Current Status: $status (Access: {$perms->access_level}) \n";
			$subcmdList .= "<tab>Set status: [";
			$subcmdList .= $this->text->makeChatcmd("enabled", "/tell <myname> config subcmd {$command->cmd} enable {$permSet}") . "] [";
			$subcmdList .= $this->text->makeChatcmd("disabled", "/tell <myname> config subcmd {$command->cmd} disable {$permSet}") . "]\n";

			$subcmdList .= "<tab>Set access level: ";
			foreach ($this->accessManager->getAccessLevels() as $accessLevel => $level) {
				if ($accessLevel == 'none') {
					continue;
				}
				if (substr($accessLevel, 0, 5) === "raid_" && !$showRaidAL) {
					continue;
				}
				$alName = $this->getAdminDescription($accessLevel);
				$subcmdList .= $this->text->makeChatcmd($alName, "/tell <myname> config subcmd {$command->cmd} admin {$permSet} $accessLevel") . "  ";
			}
			$subcmdList .= "\n\n";
		}
		return $subcmdList;
	}

	/**
	 * Get a list of all installed modules and some stats regarding the settings
	 * @return ConfigModule[]
	 */
	public function getModules(): array {
		$eventQuery = $this->db->table(EventManager::DB_TABLE)
			->select("module");
		$eventQuery->selectRaw($eventQuery->grammar->wrap("status") . "+2");
		$settingsQuery = $this->db->table(SettingManager::DB_TABLE)
			->select("module");
		$settingsQuery->selectRaw("4");

		$outerQuery = $this->db->fromSub(
			$eventQuery->unionAll($settingsQuery),
			"t"
		)->groupBy("t.module")
		->orderBy("module")
		->select("t.module");
		$stat = $outerQuery->grammar->wrap("status");
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
		/** @var Collection<string,Collection<CmdCfg>> */
		$commands = $this->commandManager->getAll()
			->groupBy("module");
		$result = [];
		foreach ($data as $row) {
			/** @var Collection<CmdCfg> */
			$moduleCmds = $commands->get($row->module, new Collection());
			$config = new ConfigModule();
			$config->name = $row->module;
			$config->description = $this->getModuleDescription($config->name);
			$config->num_commands_enabled = 0;
			$config->num_commands_disabled = 0;
			if ($moduleCmds->isNotEmpty()) {
				$config->num_commands_enabled = $moduleCmds
					->reduce(function (int $enabled, CmdCfg $cfg): int {
						return $enabled + (new Collection($cfg->permissions))->where("enabled", true)->count();
					}, 0);
				$config->num_commands_disabled = $moduleCmds
					->reduce(function (int $disabled, CmdCfg $cfg): int {
						return $disabled + (new Collection($cfg->permissions))->where("enabled", false)->count();
					}, 0);
			}
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
}
