<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\CmdPermissionSet;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\CmdCfg,
	DBSchema\CmdPermission,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

#[NCA\Instance]
class SubcommandManager {
	/** @var array<string,CmdCfg[]> */
	public array $subcommands = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<string,CmdPermission> */
	private array $cmdDefaultPermissions = [];

	/** Register a subcommand */
	public function register(
		string $module,
		string $filename,
		string $command,
		string $accessLevel,
		string $parentCommand,
		string $description='none',
		?int $defaultStatus=null
	): void {
		$command = strtolower($command);
		$module = strtoupper($module);

		$name = explode('.', $filename)[0];
		if (!Registry::instanceExists($name)) {
			$this->logger->error("Error registering handler {handler} for subcommand {command}.  Could not find instance '{instance}'.", [
				'handler' => $filename,
				'command' => $command,
				'instance' => $name,
			]);
			return;
		}

		if ($defaultStatus === null) {
			if ($this->config->general->defaultModuleStatus === 1) {
				$status = 1;
			} else {
				$status = 0;
			}
		} else {
			$status = $defaultStatus;
		}

		$defaultPerms = new CmdPermission(
			access_level: $accessLevel,
			enabled: (bool)$status,
			cmd: $command,
			permission_set: 'default',
		);
		$this->cmdDefaultPermissions[$command] = $defaultPerms;

		$this->logger->info('Adding Subcommand to list:({command}) File:({file})', [
			'command' => $command,
			'file' => $filename,
		]);
		$this->db->upsert(new CmdCfg(
			module: $module,
			verify: 1,
			file: $filename,
			description: $description,
			cmd: $command,
			dependson: $parentCommand,
			cmdevent: 'subcmd',
		));
		if (isset($this->chatBot->existing_subcmds[$command])) {
			return;
		}
		$permSets = $this->db->table(CmdPermissionSet::getTable())
			->select('name')->pluckStrings('name');
		foreach ($permSets as $permSet) {
			$this->db->table(CmdPermission::getTable())
				->insertOrIgnore(
					[
						'permission_set' => $permSet,
						'access_level' => $accessLevel,
						'cmd' => $command,
						'enabled' => (bool)$status,
						'id' => Uuid::uuid7(),
					],
				);
		}
	}

	/** Load the active subcommands into memory and activates them */
	public function loadSubcommands(): void {
		$this->logger->info('Loading enabled subcommands');

		$this->subcommands = [];

		$permissions = $this->db->table(CmdPermission::getTable())
			->where('enabled', true)
			->asObj(CmdPermission::class)
			->groupBy('cmd');

		$this->db->table(CmdCfg::getTable())
			->where('cmdevent', 'subcmd')
			->asObj(CmdCfg::class)
			->each(static function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy('permission_set')->toArray();
			})
			->filter(static function (CmdCfg $cfg): bool {
				return count($cfg->permissions) > 0;
			})
			->sort(static function (CmdCfg $row1, CmdCfg $row2): int {
				$len1 = strlen($row1->cmd);
				$len2 = strlen($row2->cmd);
				$has1 = (!str_contains($row1->cmd, '.')) ? 0 : 1;
				$has2 = (!str_contains($row2->cmd, '.')) ? 0 : 1;
				if ($len2 === $len1) {
					return $has1 <=> $has2;
				}
				return $len2 <=> $len1;
			})
			->each(function (CmdCfg $row): void {
				$this->subcommands[$row->dependson] []= $row;
			});
	}

	public function getDefaultPermissions(string $cmd): ?CmdPermission {
		return $this->cmdDefaultPermissions[$cmd] ?? null;
	}
}
