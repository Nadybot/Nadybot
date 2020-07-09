<?php

namespace Budabot\Core;

/**
 * @Instance
 */
class CommandAlias {

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\CommandManager $commandManager
	 * @Inject
	 */
	public $commandManager;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	const ALIAS_HANDLER = "CommandAlias.process";

	/**
	 * Loads active aliases into memory to activate them
	 *
	 * @return void
	 */
	public function load() {
		$this->logger->log('DEBUG', "Loading enabled command aliases");

		$data = $this->db->query("SELECT cmd, alias FROM cmd_alias_<myname> WHERE status = 1");
		foreach ($data as $row) {
			$this->activate($row->cmd, $row->alias);
		}
	}

	/**
	 * Registers a command alias
	 *
	 * @param string $module  Name of the module that defines the alias
	 * @param string $command Command for which to create an alias
	 * @param string $alias   The alias to register
	 * @param int    $status  1 for live and 0 for off
	 * @return void
	 */
	public function register($module, $command, $alias, $status=1) {
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
	 *
	 * @param string $command Command for which to activate an alias
	 * @param string $alias   The alias to register
	 * @return void
	 */
	public function activate($command, $alias) {
		$alias = strtolower($alias);

		$this->logger->log('DEBUG', "Activate Command Alias command:($command) alias:($alias)");

		$this->commandManager->activate('msg', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('priv', self::ALIAS_HANDLER, $alias, 'all');
		$this->commandManager->activate('guild', self::ALIAS_HANDLER, $alias, 'all');
	}

	/**
	 * Deactivates a command alias
	 *
	 * @param string $alias The alias to deactivate
	 * @return void
	 */
	public function deactivate($alias) {
		$alias = strtolower($alias);

		$this->logger->log('DEBUG', "Deactivate Command Alias:($alias)");

		$this->commandManager->deactivate('msg', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('priv', self::ALIAS_HANDLER, $alias);
		$this->commandManager->deactivate('guild', self::ALIAS_HANDLER, $alias);
	}

	/**
	 * Check incoming commands if they are aliases for commands and execute them
	 *
	 * @param string $message The incoming command
	 * @param string $channel The message where this command was received (guild, priv or tell)
	 * @param string $sender The name of the command sender
	 * @param \Budabot\Core\CommandReply $sendto Who to send the commands to
	 * @return bool
	 */
	public function process($message, $channel, $sender, CommandReply $sendto) {
		list($alias, $params) = explode(' ', $message, 2);
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
		} else {
			$this->commandManager->process($channel, $cmd, $sender, $sendto);
		}
	}

	/**
	 * Adds a command alias to the db
	 *
	 * @param \Budabot\Core\DBRow $row The database row to process
	 */
	public function add($row) {
		$this->logger->log('DEBUG', "Adding alias: '{$row->alias}' for command: '{$row->cmd}'");

		$sql = "INSERT INTO cmd_alias_<myname> (module, cmd, alias, status) VALUES (?, ?, ?, ?)";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->alias, $row->status);
	}

	/**
	 * Updates a command alias in the db
	 *
	 * @param \Budabot\Core\DBRow $row The database row to update
	 * @return int Number of affected rows
	 */
	public function update($row) {
		$this->logger->log('DEBUG', "Updating alias :($row->alias)");

		$sql = "UPDATE cmd_alias_<myname> SET module = ?, cmd = ?, status = ? WHERE alias = ?";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->status, $row->alias);
	}

	/**
	 * Read the database entry for an alias
	 *
	 * @param string $alias
	 * @return \Budabot\Core\DBRow
	 */
	public function get($alias) {
		$alias = strtolower($alias);

		$sql = "SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE alias = ?";
		return $this->db->queryRow($sql, $alias);
	}

	/**
	 * Get the command for which an alias actually is an alias
	 *
	 * @param string $alias The alias to look up
	 * @return string|null Null if no alias was found, otherwise the aliased command
	 */
	public function getBaseCommandForAlias($alias) {
		$row = $this->get($alias);

		// if alias doesn't exist or is disabled
		if ($row === null || $row->status != 1) {
			return null;
		}
		list($cmd) = explode(' ', $row->cmd, 2);
		return $cmd;
	}

	/**
	 * Find all aliases for a command
	 *
	 * @param string $command The command to check
	 * @return \Budabot\Core\DBRow[]
	 */
	public function findAliasesByCommand($command) {
		$sql = "SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE cmd LIKE ?";
		return $this->db->query($sql, $command);
	}

	/**
	 * Get a list of all currently enabled aliases
	 *
	 * @return \Budabot\Core\DBRow[]
	 */
	public function getEnabledAliases() {
		return $this->db->query("SELECT cmd, alias, module, status FROM cmd_alias_<myname> WHERE status = 1 ORDER BY alias ASC");
	}
}
