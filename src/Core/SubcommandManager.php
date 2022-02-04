<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Support\Collection;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdCfg;
use Nadybot\Core\DBSchema\CmdPermission;

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

	/** @var array<string,CmdPermission> */
	private array $cmdDefaultPermissions = [];

	/**
	 * @name: register
	 * @description: Registers a subcommand
	 */
	public function register(
		string $module,
		string $filename,
		string $command,
		string $accessLevel,
		string $parentCommand,
		?string $description='none',
		?int $defaultStatus=null
	): void {
		$command = strtolower($command);
		$module = strtoupper($module);

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

		$defaultPerms = new CmdPermission();
		$defaultPerms->access_level = $accessLevel;
		$defaultPerms->enabled = (bool)$status;
		$defaultPerms->cmd = $command;
		$defaultPerms->permission_set = "default";
		$this->cmdDefaultPermissions[$command] = $defaultPerms;

		$this->logger->info("Adding Subcommand to list:($command) File:($filename)");
		$this->db->table(CommandManager::DB_TABLE)
			->upsert(
				[
					"module" => $module,
					"verify" => 1,
					"file" => $filename,
					"description" => $description,
					"cmd" => $command,
					"dependson" => $parentCommand,
					"cmdevent" => "subcmd",
				],
				["cmd"],
				["module", "verify", "file", "description"]
			);
		if (isset($this->chatBot->existing_subcmds[$command])) {
			return;
		}
		$permSets = $this->db->table(CommandManager::DB_TABLE_PERM_SET)
			->select("name")->pluckAs("name", "string");
		foreach ($permSets as $permSet) {
			$this->db->table(CommandManager::DB_TABLE_PERMS)
				->insertOrIgnore(
					[
						"permission_set" => $permSet,
						"access_level" => $accessLevel,
						"cmd" => $command,
						"enabled" => (bool)$status,
					],
				);
		}
	}

	/**
	 * @name: loadSubcommands
	 * @description: Loads the active subcommands into memory and activates them
	 */
	public function loadSubcommands(): void {
		$this->logger->info("Loading enabled subcommands");

		$this->subcommands = [];

		$permissions = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->where("enabled", true)
			->asObj(CmdPermission::class)
			->groupBy("cmd");

		$this->db->table(CommandManager::DB_TABLE)
			->where("cmdevent", "subcmd")
			->asObj(CmdCfg::class)
			->each(function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy("permission_set")->toArray();
			})
			->filter(function (CmdCfg $cfg): bool {
				return count($cfg->permissions) > 0;
			})
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

	public function getDefaultPermissions(string $cmd): ?CmdPermission {
		return $this->cmdDefaultPermissions[$cmd] ?? null;
	}
}
