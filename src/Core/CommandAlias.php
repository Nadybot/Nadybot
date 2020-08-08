<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\CmdAlias;

/**
 * @Instance
 */
class CommandAlias {

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public const ALIAS_HANDLER = "CommandAlias.process";

	/**
	 * Loads active aliases into memory to activate them
	 */
	public function load(): void {
		$this->logger->log('DEBUG', "Loading enabled command aliases");

		/** @var CmdAlias[] */
		$data = $this->db->fetchAll(CmdAlias::class, "SELECT cmd, alias FROM cmd_alias_<myname> WHERE status = 1");
		foreach ($data as $row) {
			$this->activate($row->cmd, $row->alias);
		}
	}

	/**
	 * Registers a command alias
	 */
	public function register(string $module, string $command, string $alias, int $status=1): void {
		$module = strtoupper($module);
		$command = strtolower($command);
		$alias = strtolower($alias);

		$this->logger->log('DEBUG', "Registering alias: '{$alias}' for command: '$command'");

		$row = $this->get($alias);
		if ($row !== null) {
			// do not update an alias that a user created
			if (!empty($row->module)) {
				$sql = "UPDATE cmd_alias_<myname> SET module = ?, cmd = ? WHERE alias = ?";
				$this->db->exec($sql, $module, $command, $alias);
			}
		} else {
			$sql = "INSERT INTO cmd_alias_<myname> (module, cmd, alias, status) VALUES (?, ?, ?, ?)";
			$this->db->exec($sql, $module, $command, $alias, $status);
		}
	}

	/**
	 * Activates a command alias
	 */
	public function activate(string $command, string $alias): void {
		$alias = strtolower($alias);

		$this->logger->log('DEBUG', "Activate Command Alias command:($command) alias:($alias)");

		$this->commandManager->activate('msg', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('priv', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('guild', self::ALIAS_HANDLER, $alias, 'all');
	}

	/**
	 * Deactivates a command alias
	 */
	public function deactivate(string $alias): void {
		$alias = strtolower($alias);

		$this->logger->log('DEBUG', "Deactivate Command Alias:($alias)");

		$this->commandManager->deactivate('msg', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('priv', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('guild', self::ALIAS_HANDLER, $alias);
	}

	/**
	 * Check incoming commands if they are aliases for commands and execute them
	 */
	public function process(string $message, string $channel, string $sender, CommandReply $sendto): bool {
		[$alias, $params] = explode(' ', $message, 2);
		$alias = strtolower($alias);

		// Check if this is an alias for a command
		$row = $this->get($alias);
		if ($row === null) {
			return false;
		}

		$this->logger->log('DEBUG', "Command alias found command: '{$row->cmd}' alias: '{$row->alias}'");
		$cmd = $row->cmd;
		if ($params) {
			// count number of parameters and don't split more than that so that the
			// last parameter will have whatever is left

			// TODO: figure out highest numbered parameter and use that as $numMatches
			// otherwise this will break if the parameters do not include every number
			// from 1 to MAX -Tyrence
			preg_match_all("/{\\d+}/", $cmd, $matches);
			$numMatches = count(array_unique($matches[0]));
			if ($numMatches == 0) {
				$cmd .= " {0}";
			}

			$aliasParams = explode(' ', $params, $numMatches);

			// add the entire param string as the {0} parameter
			array_unshift($aliasParams, $params);

			// replace parameter placeholders with their values
			for ($i = 0; $i < count($aliasParams); $i++) {
				$cmd = str_replace('{' . $i . '}', $aliasParams[$i], $cmd);
			}
		}
		// if parameter placeholders still exist, then they did not pass enough parameters
		if (preg_match("/{\\d+}/", $cmd)) {
			return false;
		}
		$this->commandManager->process($channel, $cmd, $sender, $sendto);
		return true;
	}

	/**
	 * Adds a command alias to the db
	 */
	public function add(object $row): int {
		$this->logger->log('DEBUG', "Adding alias: '{$row->alias}' for command: '{$row->cmd}'");

		$sql = "INSERT INTO cmd_alias_<myname> (module, cmd, alias, status) VALUES (?, ?, ?, ?)";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->alias, $row->status);
	}

	/**
	 * Updates a command alias in the db
	 */
	public function update(object $row): int {
		$this->logger->log('DEBUG', "Updating alias :($row->alias)");

		$sql = "UPDATE cmd_alias_<myname> SET module = ?, cmd = ?, status = ? WHERE alias = ?";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->status, $row->alias);
	}

	/**
	 * Read the database entry for an alias
	 */
	public function get(string $alias): ?CmdAlias {
		$alias = strtolower($alias);

		$sql = "SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE alias = ?";
		return $this->db->fetch(CmdAlias::class, $sql, $alias);
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
		$sql = "SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE cmd LIKE ?";
		return $this->db->fetchAll(CmdAlias::class, $sql, $command);
	}

	/**
	 * Get a list of all currently enabled aliases
	 *
	 * @return CmdAlias[]
	 */
	public function getEnabledAliases(): array {
		return $this->db->fetchAll(CmdAlias::class, "SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE status = 1 ORDER BY alias ASC");
	}
}
