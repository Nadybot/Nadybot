<?php

namespace Budabot\Core;

use stdClass;
use Addendum\ReflectionAnnotatedMethod;
use Exception;

/**
 * @Instance
 */
class CommandManager {

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
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\AccessManager $accessManager
	 * @Inject
	 */
	public $accessManager;

	/**
	 * @var \Budabot\Core\HelpManager $helpManager
	 * @Inject
	 */
	public $helpManager;

	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\SubcommandManager $subcommandManager
	 * @Inject
	 */
	public $subcommandManager;

	/**
	 * @var \Budabot\Core\Modules\CONFIG\CommandSearchController $commandSearchController
	 * @Inject
	 */
	public $commandSearchController;

	/**
	 * @var \Budabot\Core\Modules\USAGE\UsageController $usageController
	 * @Inject
	 */
	public $usageController;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/** @var array $commands */
	public $commands;

	/**
	 * Registers a command
	 *
	 * @param string   $module        The module that wants to register a new command
	 * @param string[] $channel       The communication channels for which this command is available.
	 *                                Any combination of "msg", "priv" or "guild" can be chosen.
	 * @param string   $filename      A comma-separated list of "classname.method" handling $command
	 * @param string   $command       The command to be registered
	 * @param string   $accessLevel   The required access level to call this comnand. Valid values are:
	 *                                "raidleader", "moderator", "administrator", "none", "superadmin", "admin"
	 *                                "mod", "guild", "member", "rl", "all"
	 * @param string   $description   A short description what this command is for
	 * @param string   $help          The optional name of file with extended information (without .txt)
	 * @param int|null $defaultStatus The default state of this command:
	 *                                1 (enabled), 0 (disabled) or null (use default value as configured)
	 * @return void
	 */
	public function register($module, $channel, $filename, $command, $accessLevel, $description, $help='', $defaultStatus=null) {
		$command = strtolower($command);
		$module = strtoupper($module);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);

		if (!$this->chatBot->processCommandArgs($channel, $accessLevel)) {
			$this->logger->log('ERROR', "Invalid args for $module:command($command). Command not registered.");
			return;
		}

		if (empty($filename)) {
			$this->logger->log('ERROR', "Error registering $module:command($command).  Handler is blank.");
			return;
		}

		foreach (explode(',', $filename) as $handler) {
			list($name, $method) = explode(".", $handler);
			if (!Registry::instanceExists($name)) {
				$this->logger->log('ERROR', "Error registering method '$handler' for command '$command'.  Could not find instance '$name'.");
				return;
			}
		}

		if (!empty($help)) {
			$help = $this->helpManager->checkForHelpFile($module, $help);
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
			$this->logger->log('debug', "Adding Command to list:($command) File:($filename) Admin:({$accessLevel[$i]}) Channel:({$channel[$i]})");
			$row = $this->db->queryRow("SELECT 1 FROM cmdcfg_<myname> WHERE cmd = ? AND type = ?", $command, $channel[$i]);

			try {
				if ($row !== null) {
					$sql = "UPDATE cmdcfg_<myname> SET `module` = ?, `verify` = ?, `file` = ?, `description` = ?, `help` = ? WHERE `cmd` = ? AND `type` = ?";
					$this->db->exec($sql, $module, '1', $filename, $description, $help, $command, $channel[$i]);
				} else {
					$sql = "INSERT INTO cmdcfg_<myname> (`module`, `type`, `file`, `cmd`, `admin`, `description`, `verify`, `cmdevent`, `status`, `help`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
					$this->db->exec($sql, $module, $channel[$i], $filename, $command, $accessLevel[$i], $description, '1', 'cmd', $status, $help);
				}
			} catch (SQLException $e) {
				$this->logger->log('ERROR', "Error registering method '$handler' for command '$command': " . $e->getMessage());
			}
		}
	}

	/**
	 * Activates a command
	 *
	 * @param string $channel     The name of the channel  where this command should be activated:
	 *                            "msg", "priv" or "guild"
	 * @param string $filename    A comma-separated list of class.method which will handle the command
	 * @param string $command     The name of the command
	 * @param string $accessLevel The required access level to use this command:
	 *                            "raidleader", "moderator", "administrator", "none", "superadmin", "admin"
	 *                            "mod", "guild", "member", "rl", "all"
	 * @return void
	 */
	public function activate($channel, $filename, $command, $accessLevel='all') {
		$command = strtolower($command);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		$channel = strtolower($channel);

		$this->logger->log('DEBUG', "Activate Command:($command) Admin Type:($accessLevel) File:($filename) Channel:($channel)");

		foreach (explode(',', $filename) as $handler) {
			list($name, $method) = explode(".", $handler);
			if (!Registry::instanceExists($name)) {
				$this->logger->log('ERROR', "Error activating method $handler for command $command.  Could not find instance '$name'.");
				return;
			}
		}

		$obj = new stdClass;
		$obj->file = $filename;
		$obj->admin = $accessLevel;

		$this->commands[$channel][$command] = $obj;
	}

	/**
	 * Deactivates a command
	 *
	 * @param string $channel  The name of the channel  where this command should be deactivated:
	 *                         "msg", "priv" or "guild"
	 * @param string $filename A comma-separated list of class.method which will handle the command
	 * @param string $command  The name of the command
	 * @return void
	 */
	public function deactivate($channel, $filename, $command) {
		$command = strtolower($command);
		$channel = strtolower($channel);

		$this->logger->log('DEBUG', "Deactivate Command:($command) File:($filename) Channel:($channel)");

		unset($this->commands[$channel][$command]);
	}

	/**
	 * update the active/inactive status of a command
	 *
	 * @param string      $channel The name of the channel  where this command's status should be changed:
	 *                             "msg", "priv" or "guild"
	 * @param string      $cmd     The name of the command
	 * @param string      $module  The name of the module of the command
	 * @param int         $status  The new status: 0=off 1=on
	 * @param string|null $admin   The access level for which to update the status
	 * @return void
	 */
	public function updateStatus($channel, $cmd, $module, $status, $admin) {
		if ($channel == 'all' || $channel == '' || $channel == null) {
			$type_sql = '';
		} else {
			$type_sql = "AND `type` = '$channel'";
		}

		if ($cmd == '' || $cmd == null) {
			$cmd_sql = '';
		} else {
			$cmd_sql = "AND `cmd` = '$cmd'";
		}

		if ($module == '' || $module == null) {
			$module_sql = '';
		} else {
			$module_sql = "AND `module` = '$module'";
		}

		if ($admin == '' || $admin == null) {
			$adminSql = '';
		} else {
			$adminSql = ", admin = '$admin'";
		}

		$data = $this->db->query("SELECT * FROM cmdcfg_<myname> WHERE `cmdevent` = 'cmd' $module_sql $cmd_sql $type_sql");
		if (count($data) == 0) {
			return 0;
		}

		foreach ($data as $row) {
			if ($status == 1) {
				$this->activate($row->type, $row->file, $row->cmd, $admin);
			} elseif ($status == 0) {
				$this->deactivate($row->type, $row->file, $row->cmd);
			}
		}

		return $this->db->exec("UPDATE cmdcfg_<myname> SET status = '$status' $adminSql WHERE `cmdevent` = 'cmd' $module_sql $cmd_sql $type_sql");
	}

	/**
	 * Loads the active command into memory and activtes them
	 *
	 * @return void
	 */
	public function loadCommands() {
		$this->logger->log('DEBUG', "Loading enabled commands");

		$data = $this->db->query("SELECT * FROM cmdcfg_<myname> WHERE `status` = '1' AND `cmdevent` = 'cmd'");
		foreach ($data as $row) {
			$this->activate($row->type, $row->file, $row->cmd, $row->admin);
		}
	}

	/**
	 * Get all command handlers for a command on a specific channel
	 *
	 * @param string $command The command to lookup
	 * @param string $channel The name of the channel where this command should be searched for:
	 *                        "msg", "priv" or "guild"
	 * @return \Budabot\Core\DBRow[]
	 */
	public function get($command, $channel=null) {
		$command = strtolower($command);

		if ($channel !== null) {
			$type_sql = "AND type = '{$channel}'";
		}

		$sql = "SELECT * FROM cmdcfg_<myname> WHERE `cmd` = ? {$type_sql}";
		return $this->db->query($sql, $command);
	}

	/**
	 * Get the name of a similar command
	 *
	 * @param \Budabot\Core\DBRow $sc The command from which to get the command
	 * @return string The command name
	 */
	private function mapToCmd($sc) {
		return $sc->cmd;
	}

	/**
	 * Handle an incoming command
	 *
	 * @param string                     $channel The name of the channel where this command was received:
	 *                                            "msg", "priv" or "guild"
	 * @param string                     $message The exact message that was received
	 * @param string                     $sender  name of the person who sent the  command
	 * @param \Budabot\Core\CommandReply $sendto  Where to send replies to
	 * @return void
	 *
	 * @throws \Budabot\Core\StopExecutionException
	 * @throws \Budabot\Core\SQLException For SQL errors during command execution
	 * @throws \Exception For a generic exception during command execution
	 */
	public function process($channel, $message, $sender, CommandReply $sendto) {
		list($cmd, $params) = explode(' ', $message, 2);
		$cmd = strtolower($cmd);

		$commandHandler = $this->getActiveCommandHandler($cmd, $channel, $message);

		// if command doesn't exist
		if ($commandHandler === null) {
			// if they've disabled feedback for guild or private channel, just return
			if (($channel == 'guild' && $this->settingManager->get('guild_channel_cmd_feedback') == 0) || ($channel == 'priv' && $this->settingManager->get('private_channel_cmd_feedback') == 0)) {
				return;
			}

			$similarCommands = $this->commandSearchController->findSimilarCommands(array($cmd));
			$similarCommands = $this->commandSearchController->filterResultsByAccessLevel($sender, $similarCommands);
			$similarCommands = array_slice($similarCommands, 0, 5);
			$cmdNames = array_map(array($this, 'mapToCmd'), $similarCommands);

			$sendto->reply("Error! Unknown command. Did you mean..." . implode(", ", $cmdNames) . '?');
			return;
		}

		// if the character doesn't have access
		if (!$this->checkAccessLevel($channel, $message, $sender, $sendto, $cmd, $commandHandler)) {
			return;
		}

		try {
			$handler = $this->callCommandHandler($commandHandler, $message, $channel, $sender, $sendto);

			if ($handler === null) {
				$help = $this->getHelpForCommand($cmd, $channel, $sender);
				$sendto->reply($help);
			}
		} catch (StopExecutionException $e) {
			throw $e;
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$sendto->reply("There was an SQL error executing your command.");
		} catch (Exception $e) {
			$this->logger->log("ERROR", "Error executing '$message': " . $e->getMessage(), $e);
			$sendto->reply("There was an error executing your command: " . $e->getMessage());
		}

		try {
			// record usage stats (in try/catch block in case there is an error)
			if ($this->settingManager->get('record_usage_stats') == 1) {
				$this->usageController->record($channel, $cmd, $sender, $handler);
			}
		} catch (Exception $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
		}
	}

	/**
	 * Check if the person sending a command has the right to
	 *
	 * @param string                     $channel        The name of the channel where this command was received:
	 *                                                   "msg", "priv" or "guild"
	 * @param string                     $message        The exact message that was received
	 * @param string                     $sender         name of the person who sent the  command
	 * @param \Budabot\Core\CommandReply $sendto         Where to send replies to
	 * @param string                     $cmd            The name of the command that was requested
	 * @param \StdClass                  $commandHandler The comamnd handler for this command
	 * @return bool true if allowed to execute, otherwise false
	 */
	public function checkAccessLevel($channel, $message, $sender, $sendto, $cmd, $commandHandler) {
		if ($this->accessManager->checkAccess($sender, $commandHandler->admin) !== true) {
			if ($channel == 'msg') {
				if ($this->settingManager->get('access_denied_notify_guild') == 1) {
					$this->chatBot->sendGuild("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end>.", true);
				}
				if ($this->settingManager->get('access_denied_notify_priv') == 1) {
					$this->chatBot->sendPrivate("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end>.", true);
				}
			}

			// if they've disabled feedback for guild or private channel, just return
			if (($channel == 'guild' && $this->settingManager->get('guild_channel_cmd_feedback') == 0) || ($channel == 'priv' && $this->settingManager->get('private_channel_cmd_feedback') == 0)) {
				return false;
			}

			$sendto->reply("Error! Access denied.");
			return false;
		}
		return true;
	}

	/**
	 * Call the command handler for a given command and return which one was used
	 *
	 * @param \StdClass                  $commandHandler The comamnd handler for this command
	 * @param string                     $message        The exact message that was received
	 * @param string                     $channel        The name of the channel where this command was received:
	 *                                                   "msg", "priv" or "guild"
	 * @param string                     $sender         name of the person who sent the command
	 * @param \Budabot\Core\CommandReply $sendto         Where to send replies to
	 * @return string|null "name.method" in case of success, otherwise null
	 */
	public function callCommandHandler($commandHandler, $message, $channel, $sender, CommandReply $sendto) {
		$successfulHandler = null;

		foreach (explode(',', $commandHandler->file) as $handler) {
			list($name, $method) = explode(".", $handler);
			$instance = Registry::getInstance($name);
			if ($instance === null) {
				$this->logger->log('ERROR', "Could not find instance for name '$name'");
			} else {
				$arr = $this->checkMatches($instance, $method, $message);
				if ($arr !== false) {
					// methods will return false to indicate a syntax error, so when a false is returned,
					// we set $syntaxError = true, otherwise we set it to false
					$syntaxError = ($instance->$method($message, $channel, $sender, $sendto, $arr) === false);
					if ($syntaxError == false) {
						// we can stop looking, command was handled successfully

						$successfulHandler = $handler;
						break;
					}
				}
			}
		}

		return $successfulHandler;
	}

	/**
	 * Get the command handler that is responsible for handling a command
	 *
	 * @param string $cmd     The name of the command
	 * @param string $channel The name of the channel where this command was received:
	 *                        "msg", "priv" or "guild"
	 * @param string $message The exact message that was received
	 * @return \StdClass
	 */
	public function getActiveCommandHandler($cmd, $channel, $message) {
		// Check if a subcommands for this exists
		if (isset($this->subcommandManager->subcommands[$cmd])) {
			foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
				if ($row->type == $channel && preg_match("/^{$row->cmd}$/i", $message)) {
					return $row;
				}
			}
		}
		return $this->commands[$channel][$cmd];
	}

	/**
	 * Get the help text for a command
	 *
	 * @param string $cmd     The name of the command
	 * @param string $channel The name of the channel where this command was received:
	 *                        "msg", "priv" or "guild"
	 * @param string $sender  Name of the person who sent the command
	 * @return string|string[] The help text as one or more pages
	 */
	public function getHelpForCommand($cmd, $channel, $sender) {
		$results = $this->get($cmd, $channel);
		$result = $results[0];

		if ($result->help != '') {
			$blob = file_get_contents($result->help);
		} else {
			$blob = $this->helpManager->find($cmd, $sender);
		}
		if (!empty($blob)) {
			$msg = $this->text->makeBlob("Help ($cmd)", $blob);
		} else {
			$msg = "Error! Invalid syntax.";
		}
		return $msg;
	}

	/**
	 * Check if a received message matches the stored Regexp handler of a method
	 *
	 * @param object $instance The object where the command is defined
	 * @param string $method The method whose annotation to check
	 * @param string $message The exact received message
	 * @return string[]|bool true if there is no regexp defined, false if it didn't match, otherwise an array with the matched results
	 */
	public function checkMatches($instance, $method, $message) {
		try {
			$reflectedMethod = new ReflectionAnnotatedMethod($instance, $method);
		} catch (\ReflectionException $e) {
			// method doesn't exist (probably handled dynamically)
			return true;
		}

		$regexes = $this->retrieveRegexes($reflectedMethod);

		if (count($regexes) > 0) {
			foreach ($regexes as $regex) {
				if (preg_match($regex, $message, $arr)) {
					return $arr;
				}
			}
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Get all stored regular expression Matches for a function
	 *
	 * @param \Addendum\ReflectionAnnotatedMethod $reflectedMethod
	 * @return string[]
	 */
	public function retrieveRegexes($reflectedMethod) {
		$regexes = array();
		if ($reflectedMethod->hasAnnotation('Matches')) {
			foreach ($reflectedMethod->getAllAnnotations('Matches') as $annotation) {
				$regexes []= $annotation->value;
			}
		}
		return $regexes;
	}
}
