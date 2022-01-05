<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdCfg;

#[NCA\Instance]
class SubcommandManager {

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Logger]
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
			$this->logger->error("Invalid args for $module:subcommand($command)");
			return;
		}
		/** @var string[] $channel */

		$name = explode(".", $filename)[0];
		if (!Registry::instanceExists($name)) {
			$this->logger->error("Error registering method $filename for subcommand $command.  Could not find instance '$name'.");
			return;
		}

		if ($defaultStatus === null) {
			if ($this->config->defaultModuleStatus === 1) {
				$status = 1;
			} else {
				$status = 0;
			}
		} else {
			$status = $defaultStatus;
		}

		for ($i = 0; $i < count($channel); $i++) {
			$this->logger->info("Adding Subcommand to list:($command) File:($filename) Rights:(" . join(", ", (array)$admin).") Channel:({$channel[$i]})");

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
		$this->logger->info("Loading enabled subcommands");

		$this->subcommands = [];

		$this->db->table(CommandManager::DB_TABLE)
			->where("status", 1)
			->where("cmdevent", "subcmd")
			->asObj(CmdCfg::class)
			->sort(function (CmdCfg $row1, CmdCfg $row2): int {
				$len1 = strlen($row1->cmd);
				$len2 = strlen($row2->cmd);
				$has1 = (strpos($row1->cmd, '.') === false) ? 0 : 1;
				$has2 = (strpos($row2->cmd, '.') === false) ? 0 : 1;
				return ($len2 <=> $len1) ?: ($has1 <=> $has2);
			})
			->each(function(CmdCfg $row): void {
				$this->subcommands[$row->dependson] []= $row;
			});
	}
}
