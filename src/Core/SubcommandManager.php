<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\CmdCfg,
	DBSchema\CmdPermission,
	DBSchema\CmdPermissionSet,
	Types\AccessLevel,
	Types\Status,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/** This class manages all commands which are actually subcommands of other commands */
#[NCA\Instance]
class SubcommandManager {
	/**
	 * All registered subcommands as an associative array, keyed
	 * by the subcommand names
	 *
	 * @var array<string,CmdCfg[]>
	 */
	public array $subcommands = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	/** @var array<string,CmdPermission> */
	private array $cmdDefaultPermissions = [];

	/**
	 * List of all configured sub-commands
	 *
	 * @var array<string,true>
	 */
	private array $configuredSubcmds = [];

	/** Initialize the database before setup is called */
	public function init(): void {
		$this->db->table(CmdCfg::getTable())
			->update(['verify' => 0]);
		$this->db->table(CmdCfg::getTable())
			->where('cmdevent', 'subcmd')
			->asObj(CmdCfg::class)
			->each(function (CmdCfg $row): void {
				$this->configuredSubcmds[$row->cmd] = true;
			});
	}

	/**
	 * Register a subcommand
	 *
	 * @param string      $module        The module that defines the subcommand
	 * @param string      $filename      The handler of the subcommand in the
	 *                                   format `<class name>.<method name>`
	 * @param string      $command       The actual command
	 * @param AccessLevel $accessLevel   Access level required to run the subcommand
	 * @param string      $parentCommand The parent command of this subcommand
	 * @param string      $description   A short description of the command
	 * @param null|Status $defaultStatus The default status (enabled or disabled)
	 */
	public function register(
		string $module,
		string $filename,
		string $command,
		AccessLevel $accessLevel,
		string $parentCommand,
		string $description='none',
		?Status $defaultStatus=null
	): void {
		$command = strtolower($command);
		$module = strtoupper($module);

		$name = explode('.', $filename)[0];
		if (!Registry::hasInstance($name)) {
			$this->logger->error("Error registering handler {handler} for subcommand {command}.  Could not find instance '{instance}'.", [
				'handler' => $filename,
				'command' => $command,
				'instance' => $name,
			]);
			return;
		}

		$status = $defaultStatus ?? $this->config->general->defaultModuleStatus;

		$defaultPerms = new CmdPermission(
			access_level: $accessLevel,
			enabled: $status === Status::Enabled,
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
		if (isset($this->configuredSubcmds[$command])) {
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
						'enabled' => $status === Status::Enabled,
						'id' => Uuid::uuid7(),
					],
				);
		}
	}

	/** Load the active subcommands into memory and activate them */
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
				/** @var Collection<string,CmdPermission> */
				$keyed = $permissions->get($row->cmd, new Collection())->keyBy('permission_set');
				$row->permissions = $keyed->toArray();
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

	/** Get the default permissions of a subcommand */
	public function getDefaultPermissions(string $cmd): ?CmdPermission {
		return $this->cmdDefaultPermissions[$cmd] ?? null;
	}
}
