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
		$data = $this->db->fetchAll(CmdAlias::class, "SELECT `cmd`, `alias` FROM `cmd_alias_<myname>` WHERE `status` = 1");
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
				$sql = "UPDATE `cmd_alias_<myname>` SET `module` = ?, `cmd` = ? WHERE `alias` = ?";
				$this->db->exec($sql, $module, $command, $alias);
			}
		} else {
			$sql = "INSERT INTO `cmd_alias_<myname>` (`module`, `cmd`, `alias`, `status`) VALUES (?, ?, ?, ?)";
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
		$params = explode(' ', $message);
		while (count($params) && !isset($row)) {
			$row = $this->get(strtolower(join(' ', $params)));
			if (!isset($row)) {
				array_pop($params);
			}
		}
		if ($row === null) {
			return false;
		}
		$tokens = explode(' ', $message, count($params)+1);
		if (count($tokens) > count($params)) {
			$params = $tokens[count($params)];
		} else {
			$params = "";
		}
		$this->logger->log('DEBUG', "Command alias found command: '{$row->cmd}' alias: '{$row->alias}'");
		$cmd = $row->cmd;

		// count number of parameters and don't split more than that so that the
		// last parameter will have whatever is left

		preg_match_all("/\{(\\d+)(:.*?)?\}/", $cmd, $matches);
		$values = array_map("intval", $matches[1]);
		$numMatches = max([0, ...$values]);
		if ($numMatches === 0 && !count($values)) {
			$cmd .= " {0}";
		}

		$aliasParams = $params === "" ? [] : explode(' ', $params, $numMatches);

		// add the entire param string as the {0} parameter
		array_unshift($aliasParams, $params);

		// replace parameter placeholders with their values
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
		$this->commandManager->process($channel, $cmd, $sender, $sendto);
		return true;
	}

	/**
	 * Adds a command alias to the db
	 */
	public function add(object $row): int {
		$this->logger->log('DEBUG', "Adding alias: '{$row->alias}' for command: '{$row->cmd}'");

		$sql = "INSERT INTO `cmd_alias_<myname>` (`module`, `cmd`, `alias`, `status`) VALUES (?, ?, ?, ?)";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->alias, $row->status);
	}

	/**
	 * Updates a command alias in the db
	 */
	public function update(object $row): int {
		$this->logger->log('DEBUG', "Updating alias :($row->alias)");

		$sql = "UPDATE `cmd_alias_<myname>` SET `module` = ?, `cmd` = ?, `status` = ? WHERE `alias` = ?";
		return $this->db->exec($sql, $row->module, $row->cmd, $row->status, $row->alias);
	}

	/**
	 * Read the database entry for an alias
	 */
	public function get(string $alias): ?CmdAlias {
		$alias = strtolower($alias);

		$sql = "SELECT `cmd`, `alias`, `module`, `status` FROM `cmd_alias_<myname>` WHERE `alias` = ?";
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
		$sql = "SELECT `cmd`, `alias`, `module`, `status` FROM `cmd_alias_<myname>` WHERE `cmd` LIKE ?";
		return $this->db->fetchAll(CmdAlias::class, $sql, $command);
	}

	/**
	 * Get a list of all currently enabled aliases
	 *
	 * @return CmdAlias[]
	 */
	public function getEnabledAliases(): array {
		return $this->db->fetchAll(CmdAlias::class, "SELECT `cmd`, `alias`, `module`, `status` FROM `cmd_alias_<myname>` WHERE `status` = 1 ORDER BY `alias` ASC");
	}
}
