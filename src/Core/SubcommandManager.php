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
		/** @var string[] $channel */

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
				$this->db->table(CommandManager::DB_TABLE)
					->where("cmd", $command)
					->where("type", $channel[$i])
					->update([
						"module" => $module,
						"verify" => 1,
						"file" => $filename,
						"description" => $description,
						"dependson" => $parent_command,
						"help" => $help,
					]);
			} else {
				$this->db->table(CommandManager::DB_TABLE)
					->insert([
						"module" => $module,
						"type" => $channel[$i],
						"cmd" => $command,
						"cmdevent" => "subcmd",
						"admin" => $admin[$i],
						"verify" => 1,
						"status" => $status,
						"file" => $filename,
						"description" => $description,
						"dependson" => $parent_command,
						"help" => $help,
					]);
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
		$query = $this->db->table(CommandManager::DB_TABLE)
			->where("status", 1)
			->where("cmdevent", "subcmd");
		$query->orderByRaw($query->colFunc("LENGTH", "cmd") . " DESC")
			->orderByRaw($query->grammar->wrap("cmd") . " LIKE " . $query->grammar->quoteString("%.%") . " ASC")
			->asObj(CmdCfg::class)
			->each(function(CmdCfg $row) {
				$this->subcommands[$row->dependson] []= $row;
			});
	}
}
