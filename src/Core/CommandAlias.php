<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\preg_match;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\CmdAlias,
	Types\AccessLevel,
	Types\Status,
};
use Psr\Log\LoggerInterface;

/** This is the class that manages and runs aliases of commands */
#[NCA\Instance]
class CommandAlias {
	/**
	 * The handler to register at the command manager for executing aliases
	 *
	 * @var string
	 */
	public const ALIAS_HANDLER = 'CommandAlias.process';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private CommandManager $commandManager;

	/** Loads active aliases into memory to activate them */
	public function loadAliases(): void {
		$this->logger->info('Loading enabled command aliases');

		$this->db->table(CmdAlias::getTable())
			->where('status', 1)
			->asObj(CmdAlias::class)
			->each(function (CmdAlias $row): void {
				$this->activate($row->cmd, $row->alias);
			});
	}

	/**
	 * Registers a command alias
	 *
	 * @param string $module  Name of the module that defined the alias
	 * @param string $command The command for which we want to register an alias
	 * @param string $alias   The actual alias
	 * @param Status $status  Whether the alias should be enabled or disabled
	 */
	public function register(string $module, string $command, string $alias, Status $status=Status::Enabled): void {
		$entry = new CmdAlias(
			alias: strtolower($alias),
			module: strtoupper($module),
			cmd: strtolower($command),
			status: $status,
		);

		$row = $this->get($alias);
		if (isset($row) && $row->sameAS($entry)) {
			return;
		}
		if ($row !== null) {
			$this->logger->info('Updating {alias}', ['alias' => $entry]);
			// do not update an alias that a user created
			if (isset($row->module) && strlen($row->module) > 0) {
				$this->db->update($entry, 'alias');
			}
		} else {
			$this->logger->info('Registering {alias}', ['alias' => $entry]);
			$this->db->insert($entry);
		}
	}

	/** Activates a command alias for all permission sets */
	public function activate(string $command, string $alias): void {
		$alias = strtolower($alias);
		$entry = new AnonObj(class: 'CmdAlias', properties: ['alias' => $alias, 'cmd' => $command]);

		$this->logger->info('Activating {alias}', ['alias' => $entry]);

		foreach ($this->commandManager->getPermissionSets() as $set) {
			$this->commandManager->activate($set->name, self::ALIAS_HANDLER, $alias, AccessLevel::All);
		}
	}

	/** Deactivates a command alias for all permission sets */
	public function deactivate(string $alias): void {
		$alias = strtolower($alias);

		$this->logger->info("Deactivate Command Alias '{alias}'", ['alias' => $alias]);

		foreach ($this->commandManager->getPermissionSets() as $set) {
			$this->commandManager->deactivate($set->name, self::ALIAS_HANDLER, $alias);
		}
	}

	/**
	 * Check incoming commands if they are aliases for commands and execute them
	 *
	 * @return bool `true` if this was an alias and we executed it, `false` otherwise
	 */
	public function process(CmdContext $context): bool {
		$params = explode(' ', $context->message);
		while (count($params) && !isset($row)) {
			$row = $this->get(strtolower(implode(' ', $params)));
			if (!isset($row)) {
				array_pop($params);
			}
		}
		if (!isset($row)) {
			return false;
		}
		$tokens = explode(' ', $context->message, count($params)+1);
		if (count($tokens) > count($params)) {
			$params = $tokens[count($params)];
		} else {
			$params = '';
		}
		$this->logger->info("Command alias found command: '{command}' alias: '{alias}'", [
			'command' => $row->cmd,
			'alias' => $row->alias,
		]);
		$cmd = $row->cmd;

		// Determine highest placeholder and don't split more than that so that the
		// last parameter will have whatever is left
		$matches = Safe::pregMatchAll("/\{(\\d+)(:.*?)?\}/", $cmd);
		$placeholders = array_map('intval', $matches[1] ?? []);
		$highestPlaceholder = max([0, ...$placeholders]);
		// If there aren't any defined parameters, but player gave arguments, process them:
		if ($highestPlaceholder === 0 && !count($placeholders) && $params !== '') {
			$cmd .= ' {0}';
		}

		$aliasParams = [];
		if ($params !== '') {
			$aliasParams = explode(' ', $params, $highestPlaceholder);
			// add the entire param string as the {0} parameter
			array_unshift($aliasParams, $params);
		}

		// replace parameter placeholders with their values or the default
		$cmd = Safe::pregReplaceCallback(
			"/\{(\d+)(:.*?)?\}/",
			static function (array $matches) use ($aliasParams): string {
				if (isset($aliasParams[(int)$matches[1]])) {
					return $aliasParams[(int)$matches[1]];
				}
				if (count($matches) < 3) {
					return $matches[0];
				}
				return substr($matches[2], 1);
			},
			$cmd
		);
		// if parameter placeholders still exist, then they did not pass enough parameters
		if (preg_match("/\{\\d+(:.*?)?\}/", $cmd)) {
			return false;
		}
		$context->message = $cmd;
		$this->commandManager->syncProcessCmd($context);
		return true;
	}

	/**
	 * Adds a command alias to the db
	 *
	 * @return int `0` on error, otherwise a positive integer
	 */
	public function add(CmdAlias $row): int {
		$this->logger->info("Adding alias: '{alias}' for command: '{command}'", [
			'alias' => $row->alias,
			'command' => $row->cmd,
		]);
		return $this->db->insert($row);
	}

	/**
	 * Updates a command alias in the db
	 *
	 * @return bool success or not
	 */
	public function update(CmdAlias $row): bool {
		$this->logger->info('Updating alias ({alias})', ['alias' => $row]);
		return $this->db->update($row, 'alias') > 0;
	}

	/** Get the database entry for an alias */
	public function get(string $alias): ?CmdAlias {
		$alias = strtolower($alias);

		return $this->db->table(CmdAlias::getTable())
			->where('alias', $alias)
			->firstObj(CmdAlias::class);
	}

	/**
	 * Get the command name (excluding parameters),
	 * for which an alias actually is an alias
	 */
	public function getBaseCommandForAlias(string $alias): ?string {
		$row = $this->get($alias);

		// if alias doesn't exist or is disabled
		if ($row === null || $row->status !== Status::Enabled) {
			return null;
		}
		[$cmd] = explode(' ', $row->cmd, 2);
		return $cmd;
	}

	/**
	 * Find all aliases for a command
	 *
	 * @param string $command The command to check
	 *
	 * @return Collection<int,CmdAlias>
	 */
	public function findAliasesByCommand(string $command): Collection {
		return $this->db->table(CmdAlias::getTable())
			->whereIlike('cmd', $command)
			->asObj(CmdAlias::class);
	}

	/**
	 * Get a list of all currently enabled aliases
	 *
	 * @return Collection<int,CmdAlias>
	 */
	public function getEnabledAliases(): Collection {
		return $this->db->table(CmdAlias::getTable())
			->where('status', 1)
			->orderBy('alias')
			->asObj(CmdAlias::class);
	}
}
