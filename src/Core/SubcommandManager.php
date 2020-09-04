<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\CmdCfg;

/**
 * @Instance
 */
class SubcommandManager {

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,CmdCfg[]> */
	public array $subcommands = [];

	/**
	 * @name: register
	 * @description: Registers a subcommand
	 */
	public function register(
		 string $module,
		 ?string $channel,
		 string $filename,
		 string $command,
		 string $admin,
		 string $parent_command,
		 ?string $description='none',
		 ?string $help='',
		 ?int $defaultStatus=null
	): void {
		$command = strtolower($command);
		$module = strtoupper($module);

		if (!$this->chatBot->processCommandArgs($channel, $admin)) {
			$this->logger->log('ERROR', "Invalid args for $module:subcommand($command)");
			return;
		}

		$name = explode(".", $filename)[0];
		if (!Registry::instanceExists($name)) {
			$this->logger->log('ERROR', "Error registering method $filename for subcommand $command.  Could not find instance '$name'.");
			return;
		}

		if ($defaultStatus === null) {
			if ($this->chatBot->vars['default_module_status'] == 1) {
				$status = 1;
			} else {
				$status = 0;
			}
		} else {
			$status = $defaultStatus;
		}

		for ($i = 0; $i < count($channel); $i++) {
			$this->logger->log('DEBUG', "Adding Subcommand to list:($command) File:($filename) Admin:($admin) Channel:({$channel[$i]})");

			if ($this->chatBot->existing_subcmds[$channel[$i]][$command] == true) {
				$sql = "UPDATE cmdcfg_<myname> SET `module` = ?, `verify` = ?, `file` = ?, `description` = ?, `dependson` = ?, `help` = ? WHERE `cmd` = ? AND `type` = ?";
				$this->db->exec($sql, $module, '1', $filename, $description, $parent_command, $help, $command, $channel[$i]);
			} else {
				$sql = "INSERT INTO cmdcfg_<myname> ".
					"(`module`, `type`, `file`, `cmd`, `admin`, `description`, `verify`, `cmdevent`, `dependson`, `status`, `help`) ".
					"VALUES ".
					"(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
				$this->db->exec($sql, $module, $channel[$i], $filename, $command, $admin[$i], $description, '1', 'subcmd', $parent_command, $status, $help);
			}
		}
	}

	/**
	 * @name: loadSubcommands
	 * @description: Loads the active subcommands into memory and activates them
	 */
	public function loadSubcommands(): void {
		$this->logger->log('DEBUG', "Loading enabled subcommands");

		$this->subcommands = [];

		/** @var CmdCfg[] $data */
		$data = $this->db->fetchAll(
			CmdCfg::class,
			"SELECT * FROM cmdcfg_<myname> ".
			"WHERE `status` = '1' AND `cmdevent` = 'subcmd' ".
			"ORDER BY LENGTH(cmd) DESC, cmd LIKE '%.%' ASC"
		);
		foreach ($data as $row) {
			$this->subcommands[$row->dependson] []= $row;
		}
	}
}
