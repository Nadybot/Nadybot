<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use ReflectionClass;
use Nadybot\Core\{
	AccessManager,
	CommandAlias,
	CommandManager,
	CommandReply,
	DB,
	EventManager,
	HelpManager,
	InsufficientAccessException,
	LoggerWrapper,
	Nadybot,
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

/**
 * @Instance
 */
class ConfigController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
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
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config$/i")
	 */
	public function configCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$blob = "<header2>Quick config<end>\n".
			"<tab>Org Commands - " .
				$this->text->makeChatcmd('Enable All', '/tell <myname> config cmd enable guild') . " " .
				$this->text->makeChatcmd('Disable All', '/tell <myname> config cmd disable guild') . "\n" .
			"<tab>Private Channel Commands - " .
				$this->text->makeChatcmd('Enable All', '/tell <myname> config cmd enable priv') . " " .
				$this->text->makeChatcmd('Disable All', '/tell <myname> config cmd disable priv') . "\n" .
			"<tab>Private Message Commands - " .
				$this->text->makeChatcmd('Enable All', '/tell <myname> config cmd enable msg') . " " .
				$this->text->makeChatcmd('Disable All', '/tell <myname> config cmd disable msg') . "\n" .
			"<tab>ALL Commands - " .
				$this->text->makeChatcmd('Enable All', '/tell <myname> config cmd enable all') . " " .
				$this->text->makeChatcmd('Disable All', '/tell <myname> config cmd disable all') . "\n\n\n";
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

			$c = $this->text->makeChatcmd("Configure", "/tell <myname> config $module->name");

			$on = "<black>On<end>";
			if ($numDisabled > 0) {
				$on = $this->text->makeChatcmd("On", "/tell <myname> config mod $module->name enable all");
			}
			$off = "<black>Off<end>";
			if ($numEnabled > 0) {
				$off = $this->text->makeChatcmd("Off", "/tell <myname> config mod $module->name disable all");
			}
			$blob .= "($on / $off / $c) " . strtoupper($module->name) . " ($a)\n";
		}

		$count = count($modules);
		$msg = $this->text->makeBlob("Module Config ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler turns a channel of all modules on or off.
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config cmd (enable|disable) (all|guild|priv|msg)$/i")
	 */
	public function toggleChannelOfAllModulesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$status = ($args[1] == "enable" ? 1 : 0);
		$sqlArgs = [];
		$confirmString = "all";
		if ($args[2] == "all") {
			$typeSql = "`type` = 'guild' OR `type` = 'priv' OR `type` = 'msg'";
		} else {
			 $typeSql = "`type` = ?";
			 $sqlArgs[] = $args[2];
			 $confirmString = "all " . $args[2];
		}

		$sql = "SELECT `type`, `file`, `cmd`, `admin` ".
			"FROM `cmdcfg_<myname>` ".
			"WHERE `cmdevent` = 'cmd' AND ($typeSql)";
		$data = $this->db->fetchAll(CmdCfg::class, $sql, ...$sqlArgs);
		foreach ($data as $row) {
			if (!$this->accessManager->checkAccess($sender, $row->admin)) {
				continue;
			}
			if ($status === 1) {
				$this->commandManager->activate($row->type, $row->file, $row->cmd, $row->admin);
			} else {
				$this->commandManager->deactivate($row->type, $row->file, $row->cmd);
			}
		}

		$sql = "UPDATE `cmdcfg_<myname>` SET `status` = ? WHERE (`cmdevent` = 'cmd' OR `cmdevent` = 'subcmd') AND ($typeSql)";
		$sqlArgs = [$status, ...$sqlArgs];
		$this->db->exec($sql, ...$sqlArgs);

		$msg = "Successfully <highlight>" . ($status === 1 ? "enabled" : "disabled") . "<end> $confirmString commands.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single module on or off
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config mod (.+) (enable|disable) (priv|msg|guild|all)$/i")
	 */
	public function toggleModuleChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[2] = strtolower($args[2]);
		$args[3] = strtolower($args[3]);
		if (!$this->toggleModule($args[1], $args[3], $args[2] === "enable")) {
			if ($args[3] === "all") {
				$msg = "Could not find Module <highlight>{$args[1]}<end>.";
			} else {
				$msg = "Could not find module <highlight>{$args[1]}<end> for channel <highlight>{$args[3]}<end>.";
			}
			$sendto->reply($msg);
			return;
		}
		$color = ($args[2] === "enable") ? "green" : "red";
		if ($args[3] === "all") {
			$msg = "Updated status of module <highlight>{$args[1]}<end> to <{$color}>{$args[2]}d<end>.";
		} else {
			$msg = "Updated status of module <highlight>{$args[1]}<end> in channel <highlight>{$args[3]}<end> to <{$color}>{$args[2]}d<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single command on or off
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config (cmd|subcmd) (.+) (enable|disable) (priv|msg|guild|all)$/i")
	 */
	public function toggleCommandChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[1] = strtolower($args[1]);
		$args[3] = strtolower($args[3]);
		$args[4] = strtolower($args[4]);
		try {
			$result = $this->toggleCmd(
				$sender,
				$args[1] === "subcmd",
				$args[2],
				$args[4],
				$args[3] === "enable"
			);
		} catch (InsufficientAccessException $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$type = str_replace("cmd", "command", $args[1]);
		if (!$result) {
			if ($args[4] !== "all") {
				$msg = "Could not find {$type} <highlight>{$args[2]}<end> for channel <highlight>{$args[4]}<end>.";
			} else {
				$msg = "Could not find {$type} <highlight>{$args[2]}<end>.";
			}
			$sendto->reply($msg);
			return;
		}
		$color = ($args[3] === "enable") ? "green" : "red";
		if ($args[4] === "all") {
			$msg = "Updated status of {$type} <highlight>{$args[2]}<end> to <{$color}>{$args[3]}d<end>.";
		} else {
			$msg = "Updated status of {$type} <highlight>{$args[2]}<end> to <{$color}>{$args[3]}d<end> in channel <highlight>{$args[4]}<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler turns one or all channels of a single event on or off
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config event (.+) (enable|disable) (priv|msg|guild|all)$/i")
	 */
	public function toggleEventCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[2] = strtolower($args[2]);
		$args[3] = strtolower($args[3]);
		$temp = explode(" ", $args[1]);
		$event_type = strtolower($temp[0]);
		$file = $temp[1];

		if ( !$this->toggleEvent($event_type, $file, $args[2] === "enable") ) {
			$msg = "Could not find event <highlight>{$event_type}<end> for handler <highlight>{$file}<end>.";
			$sendto->reply($msg);
			return;
		}

		$color = ($args[2] === "enable") ? "green" : "red";
		$msg = "Updated status of event <highlight>{$event_type}<end> to <{$color}>{$args[2]}d<end>.";

		$sendto->reply($msg);
	}

	/**
	 * Enable or disable a command or subcommand for one or all channels
	 */
	public function toggleCmd(string $sender, bool $subCmd, string $cmd, string $type, bool $enable): bool {
		$cmdEvent = $subCmd ? "subcmd" : "cmd";
		$sqlArgs = [];
		$sql = "SELECT * FROM `cmdcfg_<myname>` WHERE `cmd` = ? AND `cmdevent` = ?";
		$sqlArgs = [$cmd, $cmdEvent];
		if ($type !== "all") {
			$sqlArgs []= $type;
			$sql .= " AND `type` = ?";
		}

		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, ...$sqlArgs);

		if (!$this->checkCommandAccessLevels($data, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this command.");
		}

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}

		$sqlArgs = [(int)$enable, $cmd, $cmdEvent];

		$sql = "UPDATE `cmdcfg_<myname>` SET `status` = ? ".
				"WHERE `cmd` = ? AND `cmdevent` = ?";
		if ($type !== "all") {
			$sqlArgs []= $type;
			$sql .= " AND `type` = ?";
		}
		$this->db->exec($sql, ...$sqlArgs);

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
		$sql = "SELECT *, 'event' AS cmdevent FROM `eventcfg_<myname>` ".
			"WHERE `file` = ? AND `type` = ? AND `type` != 'setup'";

		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, $file, $eventType);

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}

		$this->db->exec(
			"UPDATE `eventcfg_<myname>` SET `status` = ? ".
			"WHERE `type` = ? AND `file` = ? AND `type` != 'setup'",
			(int)$enable,
			$eventType,
			$file
		);

		return true;
	}

	/**
	 * Enabel or disable all commands and events for a module
	 * @param string $module Name of the module
	 * @param string $channel  msg, prov or guild
	 * @param bool $enable true for enabling, false for disabling
	 * @return bool True for success, False if the module doesn't exist
	 */
	public function toggleModule(string $module, string $channel, bool $enable): bool {
		$sqlArgs = [];
		if ($channel === "all") {
			$sql = "SELECT `status`, `type`, `file`, `cmd`, `admin`, `cmdevent` FROM `cmdcfg_<myname>` WHERE `module` = ? ".
						"UNION ".
					"SELECT `status`, `type`, `file`, '' AS cmd, '' AS admin, 'event' AS cmdevent FROM `eventcfg_<myname>` WHERE `module` = ? AND `type` != 'setup'";
			$sqlArgs = [$module, $module];
		} else {
			$sql = "SELECT `status`, `type`, `file`, `cmd`, `admin`, `cmdevent` FROM `cmdcfg_<myname>` WHERE `module` = ? AND `type` = ? ".
						"UNION ".
					"SELECT `status`, `type`, `file`, '' AS `cmd`, '' AS `admin`, 'event' AS `cmdevent` FROM `eventcfg_<myname>` WHERE `module` = ? AND `type` != 'setup'";
			$sqlArgs = [$module, $channel, $module];
		}

		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, ...$sqlArgs);

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$this->toggleCmdCfg($row, $enable);
		}

		if ($channel === "all") {
			$this->db->exec("UPDATE `cmdcfg_<myname>` SET `status` = ? WHERE `module` = ?", (int)$enable, $module);
			$this->db->exec("UPDATE `eventcfg_<myname>` SET `status` = ? WHERE `module` = ? AND `type` != 'setup'", (int)$enable, $module);
		} else {
			$this->db->exec("UPDATE `cmdcfg_<myname>` SET `status` = ? WHERE `module` = ? AND `type` = ?", (int)$enable, $module, $channel);
			$this->db->exec("UPDATE `eventcfg_<myname>` SET `status` = ? WHERE `module` = ? AND `type` != 'setup'", (int)$enable, $module);
		}

		// for subcommands which are handled differently
		$this->subcommandManager->loadSubcommands();
		return true;
	}

	public function toggleCmdCfg(CmdCfg $cfg, bool $enable): void {
		if ((bool)$cfg->status === $enable) {
			return;
		}
		if ($cfg->cmdevent === "event") {
			if ($enable) {
				$this->eventManager->activate($cfg->type, $cfg->file);
			} else {
				$this->eventManager->deactivate($cfg->type, $cfg->file);
			}
		} elseif ($cfg->cmdevent === "cmd") {
			if ($enable) {
				$this->commandManager->activate($cfg->type, $cfg->file, $cfg->cmd, $cfg->admin);
			} else {
				$this->commandManager->deactivate($cfg->type, $cfg->file, $cfg->cmd, $cfg->admin);
			}
		}
	}

	/**
	 * This command handler sets command's access level on a particular channel.
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config (subcmd|cmd) (.+) admin (msg|priv|guild|all) (.+)$/i")
	 */
	public function setAccessLevelOfChannelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$category = strtolower($args[1]);
		$command = strtolower($args[2]);
		$channel = strtolower($args[3]);
		$accessLevel = $args[4];

		$type = "command";
		try {
			if ($category === "cmd") {
				$result = $this->changeCommandAL($sender, $command, $channel, $accessLevel);
			} else {
				$type = "subcommand";
				$result = $this->changeSubcommandAL($sender, $command, $channel, $accessLevel);
			}
		} catch (InsufficientAccessException $e) {
			$msg = "You do not have the required access level to change this {$type}.";
			$sendto->reply($msg);
			return;
		}

		if ($result === 0) {
			if ($channel === "all") {
				$msg = "Could not find {$type} <highlight>{$command}<end>.";
			} else {
				$msg = "Could not find {$type} <highlight>{$command}<end> for channel <highlight>{$channel}<end>.";
			}
			$sendto->reply($msg);
			return;
		}
		if ($result === -1) {
			$msg = "You may not set the access level for a {$type} above your own access level.";
			$sendto->reply($msg);
			return;
		}
		if ($channel === "all") {
			$msg = "Updated access of {$type} <highlight>{$command}<end> to <highlight>{$accessLevel}<end>.";
		} else {
			$msg = "Updated access of {$type} <highlight>{$command}<end> in channel <highlight>{$channel}<end> to <highlight>{$accessLevel}<end>.";
		}
		$sendto->reply($msg);
	}

	public function changeCommandAL(string $sender, string $command, string $channel, string $accessLevel): int {
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		$sqlArgs = [$command];
		if ($channel === "all") {
			$sql = "SELECT * FROM `cmdcfg_<myname>` WHERE `cmd` = ? AND `cmdevent` = 'cmd'";
		} else {
			$sql = "SELECT * FROM `cmdcfg_<myname>` WHERE `cmd` = ? AND `type` = ? AND `cmdevent` = 'cmd'";
			$sqlArgs []= $channel;
		}
		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, ...$sqlArgs);

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
		$sql = "SELECT * FROM `cmdcfg_<myname>` WHERE `type` = ? AND `cmdevent` = 'subcmd' AND `cmd` = ?";
		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, $channel, $command);
		if (count($data) === 0) {
			return 0;
		} elseif (!$this->checkCommandAccessLevels($data, $sender)) {
			throw new InsufficientAccessException("You do not have the required access level to change this subcommand.");
		} elseif (!$this->accessManager->checkAccess($sender, $accessLevel)) {
			return -1;
		}
		$this->db->exec("UPDATE cmdcfg_<myname> SET `admin` = ? WHERE `type` = ? AND `cmdevent` = 'subcmd' AND `cmd` = ?", $accessLevel, $channel, $command);
		$this->subcommandManager->loadSubcommands();
		return 1;
	}

	/**
	 * Check if sender has access to all commands in $data
	 *
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
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config cmd ([a-z0-9_]+)$/i")
	 */
	public function configCommandCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = strtolower($args[1]);

		$aliasCmd = $this->commandAlias->getBaseCommandForAlias($cmd);
		if ($aliasCmd !== null) {
			$cmd = $aliasCmd;
		}

		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, "SELECT * FROM `cmdcfg_<myname>` WHERE `cmd` = ?", $cmd);
		if (count($data) === 0) {
			$msg = "Could not find command <highlight>$cmd<end>.";
			$sendto->reply($msg);
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

		$help = $this->helpManager->find($cmd, $sender);
		if ($help !== null) {
			$blob .= "<header>Help ($cmd)<end>\n\n" . $help;
		}

		$msg = $this->text->makeBlob(ucfirst($cmd)." Config", $blob);
		$sendto->reply($msg);
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
			glob("{$path}/*", GLOB_NOSORT),
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
	 * Note: This handler has not been not registered, only activated.
	 *
	 * @Matches("/^config ([a-z0-9_]+)$/i")
	 */
	public function configModuleCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$module = strtoupper($args[1]);
		$found = false;

		$on = $this->text->makeChatcmd("Enable", "/tell <myname> config mod {$module} enable all");
		$off = $this->text->makeChatcmd("Disable", "/tell <myname> config mod {$module} disable all");

		$blob = "Enable/disable entire module: ($on/$off)\n";
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
			$blob .= "<tab>" . $row->getData()->description ?? "";

			if ($row->isEditable() && $this->accessManager->checkAccess($sender, $row->getData()->admin)) {
				$blob .= " (" . $this->text->makeChatcmd("Modify", "/tell <myname> settings change " . $row->getData()->name) . ")";
			}

			$blob .= ": " . $row->displayValue($sender) . "\n";
		}

		$data = $this->getAllRegisteredCommands($module);
		if (count($data) > 0) {
			$found = true;
			$blob .= "\n<header2>Commands<end>\n";
		}
		foreach ($data as $row) {
			$guild = '';
			$priv = '';
			$msg = '';

			if ($row->cmdevent === 'cmd') {
				$on = $this->text->makeChatcmd("ON", "/tell <myname> config cmd $row->cmd enable all");
				$off = $this->text->makeChatcmd("OFF", "/tell <myname> config cmd $row->cmd disable all");
				$cmdNameLink = $this->text->makeChatcmd($row->cmd, "/tell <myname> config cmd $row->cmd");
			} elseif ($row->cmdevent === 'subcmd') {
				$on = $this->text->makeChatcmd("ON", "/tell <myname> config subcmd $row->cmd enable all");
				$off = $this->text->makeChatcmd("OFF", "/tell <myname> config subcmd $row->cmd disable all");
				$cmdNameLink = $row->cmd;
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
		$data = $this->db->fetchAll(EventCfg::class, "SELECT * FROM `eventcfg_<myname>` WHERE `type` != 'setup' AND `module` = ?", $module);
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
		$sendto->reply($msg);
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
		$data = $this->db->fetchAll(CmdCfg::class, "SELECT * FROM `cmdcfg_<myname>` WHERE `cmd` = ? AND `type` = ?", $cmd, $type);
		if (count($data) == 0) {
			$msg .= "<red>Unused<end>\n";
		} elseif (count($data) > 1) {
			$this->logger->log("ERROR", "Multiple rows exists for cmd: '$cmd' and type: '$type'");
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
		$msg .= "Set status: ";
		$msg .= $this->text->makeChatcmd("Enabled", "/tell <myname> config cmd {$cmd} enable {$type}") . "  ";
		$msg .= $this->text->makeChatcmd("Disabled", "/tell <myname> config cmd {$cmd} disable {$type}") . "\n";

		$msg .= "Set access level: ";
		$showRaidAL = $this->db->queryRow(
			"SELECT * FROM `cmdcfg_<myname>` WHERE `module`=? AND `status`=?",
			'RAID_MODULE',
			1
		) !== null;
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
	private function getSubCommandInfo($cmd, $type) {
		$subcmd_list = '';
		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, "SELECT * FROM `cmdcfg_<myname>` WHERE dependson = ? AND `type` = ? AND `cmdevent` = 'subcmd'", $cmd, $type);
		$showRaidAL = $this->db->queryRow(
			"SELECT * FROM `cmdcfg_<myname>` WHERE `module`=? AND `status`=?",
			'RAID_MODULE',
			1
		) !== null;
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
			$subcmd_list .= "<tab>Set status: ";
			$subcmd_list .= $this->text->makeChatcmd("Enabled", "/tell <myname> config subcmd {$row->cmd} enable {$type}") . "  ";
			$subcmd_list .= $this->text->makeChatcmd("Disabled", "/tell <myname> config subcmd {$row->cmd} disable {$type}") . "\n";

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
		$sql = "SELECT ".
				"`module`, ".
				"SUM(CASE WHEN `status` = 0 THEN 1 ELSE 0 END) AS count_cmd_disabled, ".
				"SUM(CASE WHEN `status` = 1 THEN 1 ELSE 0 END) AS count_cmd_enabled, ".
				"SUM(CASE WHEN `status` = 2 THEN 1 ELSE 0 END) AS count_events_disabled, ".
				"SUM(CASE WHEN `status` = 3 THEN 1 ELSE 0 END) AS count_events_enabled, ".
				"SUM(CASE WHEN `status` = 4 THEN 1 ELSE 0 END) AS count_settings ".
			"FROM (".
				"SELECT `module`, `status` FROM `cmdcfg_<myname>` WHERE `cmdevent` = 'cmd' ".
					"UNION ALL ".
				"SELECT `module`, `status`+2 FROM `eventcfg_<myname>` ".
					"UNION ALL ".
				"SELECT `module`, 4 FROM `settings_<myname>` ".
			") t ".
			"GROUP BY ".
				"`module` ".
			"ORDER BY ".
				"`module` ASC";

		$data = $this->db->query($sql);
		$result = [];
		foreach ($data as $row) {
			$config = new ConfigModule();
			$config->name = $row->module;
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
		/** @var CmdCfg[] $data */
		$showRaidAL = $this->db->queryRow(
			"SELECT * FROM `cmdcfg_<myname>` WHERE `module`=? AND `status`=?",
			'RAID_MODULE',
			1
		) !== null;
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

		/** @var Setting[] $data */
		$data = $this->db->fetchAll(Setting::class, "SELECT * FROM `settings_<myname>` WHERE `module` = ? ORDER BY `mode`, `description`", $module);
		$data = array_map(
			function(Setting $setting): SettingHandler {
				return $this->settingManager->getSettingHandler($setting);
			},
			$data
		);
		return $data;
	}

	/**
	 * @return CmdCfg[]
	 */
	public function getAllRegisteredCommands(string $module): array {
		$sql = "SELECT ".
				"*, ".
				"SUM(CASE WHEN type = 'guild' THEN 1 ELSE 0 END) guild_avail, ".
				"SUM(CASE WHEN type = 'guild' AND status = 1 THEN 1 ELSE 0 END) guild_status, ".
				"MAX(CASE WHEN type = 'guild' THEN admin ELSE NULL END) guild_al, ".
				"SUM(CASE WHEN type = 'priv' THEN 1 ELSE 0 END) priv_avail, ".
				"SUM(CASE WHEN type = 'priv' AND status = 1 THEN 1 ELSE 0 END) priv_status, ".
				"MAX(CASE WHEN type = 'priv' THEN admin ELSE null END) priv_al, ".
				"SUM(CASE WHEN type = 'msg' THEN 1 ELSE 0 END) msg_avail, ".
				"SUM(CASE WHEN type = 'msg' AND status = 1 THEN 1 ELSE 0 END) msg_status, ".
				"MAX(CASE WHEN type = 'msg' THEN admin ELSE null END) msg_al ".
			"FROM ".
				"cmdcfg_<myname> c ".
			"WHERE ".
				"(`cmdevent` = 'cmd' OR `cmdevent` = 'subcmd') ".
				"AND `module` = ? ".
			"GROUP BY ".
				"cmd";
		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(CmdCfg::class, $sql, $module);
		return $data;
	}

	public function getRegisteredCommand(string $module, string $command): ?CmdCfg {
		$sql = "SELECT ".
				"*, ".
				"SUM(CASE WHEN type = 'guild' THEN 1 ELSE 0 END) guild_avail, ".
				"SUM(CASE WHEN type = 'guild' AND status = 1 THEN 1 ELSE 0 END) guild_status, ".
				"MAX(CASE WHEN type = 'guild' THEN admin ELSE NULL END) guild_al, ".
				"SUM(CASE WHEN type = 'priv' THEN 1 ELSE 0 END) priv_avail, ".
				"SUM(CASE WHEN type = 'priv' AND status = 1 THEN 1 ELSE 0 END) priv_status, ".
				"MAX(CASE WHEN type = 'priv' THEN admin ELSE null END) priv_al, ".
				"SUM(CASE WHEN type = 'msg' THEN 1 ELSE 0 END) msg_avail, ".
				"SUM(CASE WHEN type = 'msg' AND status = 1 THEN 1 ELSE 0 END) msg_status, ".
				"MAX(CASE WHEN type = 'msg' THEN admin ELSE null END) msg_al ".
			"FROM ".
				"cmdcfg_<myname> c ".
			"WHERE ".
				"(`cmdevent` = 'cmd' OR `cmdevent` = 'subcmd') ".
				"AND `module` = ? ".
				"AND `cmd` = ? ".
			"GROUP BY ".
				"cmd";
		/** @var ?CmdCfg $data */
		$data = $this->db->fetch(CmdCfg::class, $sql, $module, $command);
		return $data;
	}
}
