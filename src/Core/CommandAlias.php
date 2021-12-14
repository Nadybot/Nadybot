<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\CmdAlias;

#[NCA\Instance]
class CommandAlias {
	public const DB_TABLE = "cmd_alias_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public const ALIAS_HANDLER = "CommandAlias.process";

	/**
	 * Loads active aliases into memory to activate them
	 */
	public function load(): void {
		$this->logger->info("Loading enabled command aliases");

		$this->db->table(self::DB_TABLE)
			->where("status", 1)
			->asObj(CmdAlias::class)
			->each(function (CmdAlias $row) {
				$this->activate($row->cmd, $row->alias);
			});
	}

	/**
	 * Registers a command alias
	 */
	public function register(string $module, string $command, string $alias, int $status=1): void {
		$module = strtoupper($module);
		$command = strtolower($command);
		$alias = strtolower($alias);

		$this->logger->info("Registering alias: '{$alias}' for command: '$command'");

		$row = $this->get($alias);
		if ($row !== null) {
			// do not update an alias that a user created
			if (!empty($row->module)) {
				$this->db->table(self::DB_TABLE)
					->where("alias", $alias)
					->update(["module" => $module, "cmd" => $command]);
			}
		} else {
			$this->db->table(self::DB_TABLE)
				->insert([
					"module" => $module,
					"cmd" => $command,
					"alias" => $alias,
					"status" => $status
				]);
		}
	}

	/**
	 * Activates a command alias
	 */
	public function activate(string $command, string $alias): void {
		$alias = strtolower($alias);

		$this->logger->info("Activate Command Alias command:($command) alias:($alias)");

		$this->commandManager->activate('msg', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('priv', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('guild', self::ALIAS_HANDLER, $alias, 'all');
	}

	/**
	 * Deactivates a command alias
	 */
	public function deactivate(string $alias): void {
		$alias = strtolower($alias);

		$this->logger->info("Deactivate Command Alias:($alias)");

		$this->commandManager->deactivate('msg', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('priv', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('guild', self::ALIAS_HANDLER, $alias);
	}

	/**
	 * Check incoming commands if they are aliases for commands and execute them
	 */
	public function process(CmdContext $context): bool {
		$params = explode(' ', $context->message);
		while (count($params) && !isset($row)) {
			$row = $this->get(strtolower(join(' ', $params)));
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
			$params = "";
		}
		$this->logger->info("Command alias found command: '{$row->cmd}' alias: '{$row->alias}'");
		$cmd = $row->cmd;

		// Determine highest placeholder and don't split more than that so that the
		// last parameter will have whatever is left
		preg_match_all("/\{(\\d+)(:.*?)?\}/", $cmd, $matches);
		$placeholders = array_map("intval", $matches[1]);
		$highestPlaceholder = max([0, ...$placeholders]);
		// If there aren't any defined parameters, but player gave arguments, process them:
		if ($highestPlaceholder === 0 && !count($placeholders) && $params !== "") {
			$cmd .= " {0}";
		}

		$aliasParams = [];
		if ($params !== "") {
			$aliasParams = explode(' ', $params, $highestPlaceholder);
			// add the entire param string as the {0} parameter
			array_unshift($aliasParams, $params);
		}

		// replace parameter placeholders with their values or the default
		$cmd = preg_replace_callback(
			"/\{(\d+)(:.*?)?\}/",
			function (array $matches) use ($aliasParams): string {
				if (isset($aliasParams[$matches[1]])) {
					return $aliasParams[$matches[1]];
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
		$this->commandManager->processCmd($context);
		return true;
	}

	/**
	 * Adds a command alias to the db
	 */
	public function add(object $row): int {
		$this->logger->info("Adding alias: '{$row->alias}' for command: '{$row->cmd}'");
		return $this->db->table(self::DB_TABLE)->insert([
			"module" => $row->module,
			"cmd" => $row->cmd,
			"alias" => $row->alias,
			"status" => $row->status,
		]) ? 1 : 0;
	}

	/**
	 * Updates a command alias in the db
	 */
	public function update(object $row): int {
		$this->logger->info("Updating alias :($row->alias)");
		return $this->db->table(self::DB_TABLE)
			->where("alias", $row->alias)
			->update([
				"module" => $row->module,
				"cmd" => $row->cmd,
				"status" => $row->status
			]);
	}

	/**
	 * Read the database entry for an alias
	 */
	public function get(string $alias): ?CmdAlias {
		$alias = strtolower($alias);

		return $this->db->table(self::DB_TABLE)->where("alias", $alias)->asObj(CmdAlias::class)->first();
	}

	/**
	 * Get the command for which an alias actually is an alias
	 */
	public function getBaseCommandForAlias(string $alias): ?string {
		$row = $this->get($alias);

		// if alias doesn't exist or is disabled
		if ($row === null || $row->status != 1) {
			return null;
		}
		[$cmd] = explode(' ', $row->cmd, 2);
		return $cmd;
	}

	/**
	 * Find all aliases for a command
	 *
	 * @param string $command The command to check
	 * @return CmdAlias[]
	 */
	public function findAliasesByCommand(string $command): array {
		return $this->db->table(self::DB_TABLE)
			->whereIlike("cmd", $command)
			->asObj(CmdAlias::class)
			->toArray();
	}

	/**
	 * Get a list of all currently enabled aliases
	 *
	 * @return CmdAlias[]
	 */
	public function getEnabledAliases(): array {
		return $this->db->table(self::DB_TABLE)
			->where("status", 1)
			->orderBy("alias")
			->asObj(CmdAlias::class)
			->toArray();
	}
}
