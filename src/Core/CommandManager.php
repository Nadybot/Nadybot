<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Throwable;
use ReflectionException;
use Addendum\ReflectionAnnotatedMethod;
use Nadybot\Core\DBSchema\CmdCfg;
use Nadybot\Core\Modules\CONFIG\CommandSearchController;
use Nadybot\Core\Modules\LIMITS\LimitsController;
use Nadybot\Core\Modules\USAGE\UsageController;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @Instance
 * @ProvidesEvent("command(forbidden)")
 * @ProvidesEvent("command(success)")
 * @ProvidesEvent("command(unknown)")
 * @ProvidesEvent("command(help)")
 * @ProvidesEvent("command(error)")
 */
class CommandManager implements MessageEmitter {
	public const DB_TABLE = "cmdcfg_<myname>";

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public HelpManager $helpManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public CommandSearchController $commandSearchController;

	/** @Inject */
	public UsageController $usageController;

	/** @Inject */
	public LimitsController $limitsController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,array<string,CommandHandler>> $commands */
	public array $commands;

	/** @Setup */
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
	}

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
	 */
	public function register(string $module, ?string $channel, string $filename, string $command, string $accessLevel, string $description, ?string $help='', $defaultStatus=null): void {
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
			$name = explode(".", $handler)[0];
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
			try {
				$this->db->table(self::DB_TABLE)
					->upsert(
						[
							"module" => $module,
							"verify" => 1,
							"file" => $filename,
							"description" => $description,
							"help" => $help,
							"cmd" => $command,
							"cmdevent" => "cmd",
							"type" => $channel[$i],
							"admin" => $accessLevel[$i],
							"status" => $status,
						],
						["cmd", "type"],
						["module", "verify", "file", "description", "help"]
					);
			} catch (SQLException $e) {
				$this->logger->log('ERROR', "Error registering method '$handler' for command '$command': " . $e->getMessage(), $e);
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
	public function activate(string $channel, string $filename, string $command, ?string $accessLevel='all'): void {
		$command = strtolower($command);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		$channel = strtolower($channel);

		$this->logger->log('DEBUG', "Activate Command:($command) Admin Type:($accessLevel) File:($filename) Channel:($channel)");

		foreach (explode(',', $filename) as $handler) {
			[$name, $method] = explode(".", $handler);
			if (!Registry::instanceExists($name)) {
				$this->logger->log('ERROR', "Error activating method $handler for command $command.  Could not find instance '$name'.");
				return;
			}
		}

		$obj = new CommandHandler($filename, $accessLevel);

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
	public function deactivate(string $channel, string $filename, string $command): void {
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
	 * @return int
	 */
	public function updateStatus(?string $channel, ?string $cmd, ?string $module, int $status, ?string $admin): int {
		$query = $this->db->table(self::DB_TABLE)
			->where("cmdevent", "cmd");
		if ($module !== '' && $module !== null) {
			$query->where("module", $module);
		}
		if ($cmd !== '' && $cmd !== null) {
			$query->where("cmd", $cmd);
		}
		if ($channel !== 'all' && $channel !== '' && $channel !== null) {
			$query->where("type", $channel);
		}

		/** @var CmdCfg[] */
		$data = $query->asObj(CmdCfg::class)->toArray();
		if (count($data) === 0) {
			return 0;
		}

		$update = ["status" => $status];
		if ($admin !== '' && $admin !== null) {
			$update["admin"] = $admin;
		}

		foreach ($data as $row) {
			if ($status === 1) {
				$this->activate($row->type, $row->file, $row->cmd, $admin);
			} elseif ($status === 0) {
				$this->deactivate($row->type, $row->file, $row->cmd);
			}
		}

		return $query->update($update);
	}

	/**
	 * Loads the active command into memory and activates them
	 */
	public function loadCommands(): void {
		$this->logger->log('DEBUG', "Loading enabled commands");

		/** @var CmdCfg[] */
		$data = $this->db->table(self::DB_TABLE)
			->where("status", 1)
			->where("cmdevent", "cmd")
			->asObj(CmdCfg::class)->toArray();
		foreach ($data as $row) {
			$this->activate($row->type, $row->file, $row->cmd, $row->admin);
		}
	}

	/**
	 * Get all command handlers for a command on a specific channel
	 *
	 * @return CmdCfg[]
	 */
	public function get(string $command, ?string $channel=null): array {
		$query = $this->db->table(self::DB_TABLE)
			->where("cmd", strtolower($command));

		if ($channel !== null) {
			$query->where("type", $channel);
		}

		return $query->asObj(CmdCfg::class)->toArray();
	}

	/**
	 * Get the name of a similar command
	 */
	private function mapToCmd(DBRow $sc): string {
		return $sc->cmd;
	}

	/**
	 * Handle an incoming command
	 *
	 * @throws \Nadybot\Core\StopExecutionException
	 * @throws \Nadybot\Core\SQLException For SQL errors during command execution
	 * @throws \Exception For a generic exception during command execution
	 */
	public function process(string $channel, string $message, string $sender, CommandReply $sendto): void {
		$context = new CmdContext();
		$context->channel = $channel;
		$context->message = $message;
		$context->sender = $sender;
		$context->sendto = $sendto;
		$this->processCmd($context);
	}

	public function processCmd(CmdContext $context): void {
		$cmd = explode(' ', $context->message, 2)[0];
		$cmd = strtolower($cmd);

		if ($this->limitsController->isIgnored($context->sender)) {
			return;
		}
		$commandHandler = $this->getActiveCommandHandler($cmd, $context->channel, $context->message);
		$event = new CmdEvent();
		$event->channel = $context->channel;
		$event->cmd = $cmd;
		$event->sender = $context->sender;
		$event->cmdHandler = $commandHandler;

		// if command doesn't exist
		if ($commandHandler === null) {
			// if they've disabled feedback for guild or private channel, just return
			if (
				($context->channel === 'guild' && !$this->settingManager->getBool('guild_channel_cmd_feedback'))
				|| ($context->channel == 'priv' && !$this->settingManager->getBool('private_channel_cmd_feedback'))
			) {
				return;
			}

			$similarCommands = $this->commandSearchController->findSimilarCommands([$cmd]);
			$similarCommands = $this->commandSearchController->filterResultsByAccessLevel($context->sender, $similarCommands);
			$similarCommands = array_slice($similarCommands, 0, 5);
			$cmdNames = array_map([$this, 'mapToCmd'], $similarCommands);

			$context->reply("Error! Unknown command. Did you mean..." . implode(", ", $cmdNames) . '?');
			$event->type = "command(unknown)";
			$this->eventManager->fireEvent($event);
			return;
		}

		// if the character doesn't have access
		if (!$this->checkAccessLevel($context->channel, $context->message, $context->sender, $context->sendto, $cmd, $commandHandler)) {
			$event->type = "command(forbidden)";
			$this->eventManager->fireEvent($event);
			return;
		}

		try {
			$handler = $this->executeCommandHandler($commandHandler, $context);
			$event->type = "command(success)";

			if ($handler === null) {
				$help = $this->getHelpForCommand($cmd, $context->channel, $context->sender);
				$context->reply($help);
				$event->type = "command(help)";
				$this->eventManager->fireEvent($event);
			}
		} catch (StopExecutionException $e) {
			$event->type = "command(error)";
			throw $e;
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$context->reply("There was an SQL error executing your command.");
			$event->type = "command(error)";
		} catch (Throwable $e) {
			$this->logger->log(
				"ERROR",
				"Error executing '{$context->message}': " . $e->getMessage(),
				$e
			);
			$context->reply("There was an error executing your command: " . $e->getMessage());
			$event->type = "command(error)";
		}
		$this->eventManager->fireEvent($event);

		try {
			// record usage stats (in try/catch block in case there is an error)
			if ($this->settingManager->getBool('record_usage_stats')) {
				$this->usageController->record($context->channel, $cmd, $context->sender, $handler);
			}
		} catch (Exception $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
		}
	}

	/**
	 * Check if the person sending a command has the right to
	 *
	 * @param string                $channel        The name of the channel where this command was received:
	 *                                              "msg", "priv" or "guild"
	 * @param string                $message        The exact message that was received
	 * @param string                $sender         name of the person who sent the  command
	 * @param CommandReply $sendto  Where to send replies to
	 * @param string                $cmd            The name of the command that was requested
	 * @param CommandHandler        $commandHandler The command handler for this command
	 * @return bool true if allowed to execute, otherwise false
	 */
	public function checkAccessLevel(string $channel, string $message, string $sender, CommandReply $sendto, string $cmd, CommandHandler $commandHandler): bool {
		if ($this->accessManager->checkAccess($sender, $commandHandler->admin) === true) {
			return true;
		}
		if ($channel == 'msg') {
			$r = new RoutableMessage("Player <highlight>$sender<end> was denied access to command <highlight>$cmd<end>.");
			$r->appendPath(new Source(Source::SYSTEM, "access-denied"));
			$this->messageHub->handle($r);
		}

		// if they've disabled feedback for guild or private channel, just return
		if (($channel == 'guild' && !$this->settingManager->getBool('guild_channel_cmd_feedback')) || ($channel == 'priv' && !$this->settingManager->getBool('private_channel_cmd_feedback'))) {
			return false;
		}

		$sendto->reply("Error! Access denied.");
		return false;
	}

	/**
	 * Call the command handler for a given command and return which one was used
	 */
	public function callCommandHandler(CommandHandler $commandHandler, string $message, string $channel, string $sender, CommandReply $sendto): ?string {
		$context = new CmdContext();
		$context->message = $message;
		$context->channel = $channel;
		$context->sender = $sender;
		$context->sendto = $sendto;
		return $this->executeCommandHandler($commandHandler, $context);
	}

	public function executeCommandHandler(CommandHandler $commandHandler, CmdContext $context): ?string {
		$successfulHandler = null;

		foreach (explode(',', $commandHandler->file) as $handler) {
			[$name, $method] = explode(".", $handler);
			$instance = Registry::getInstance($name);
			if ($instance === null) {
				$this->logger->log('ERROR', "Could not find instance for name '$name'");
			} else {
				$arr = $this->checkMatches($instance, $method, $context->message);
				if ($arr !== false) {
					$context->args = is_bool($arr) ? [] : $arr;
					$refClass = new ReflectionClass($instance);
					$refMethod = $refClass->getMethod($method);
					$params = $refMethod->getParameters();
					// methods will return false to indicate a syntax error, so when a false is returned,
					// we set $syntaxError = true, otherwise we set it to false
					if (count($params) > 0
						&& $params[0]->hasType()
						&& ($type = $params[0]->getType())
						&& ($type instanceof ReflectionNamedType)
						&& ($type->getName() === CmdContext::class)
					) {
						$syntaxError = $instance->$method($context, ...array_slice($context->args, 1)) === false;
					} else {
						$syntaxError = ($instance->$method($context->message, $context->channel, $context->sender, $context->sendto, $context->args) === false);
					}
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
	 */
	public function getActiveCommandHandler(string $cmd, string $channel, string $message): ?CommandHandler {
		// Check if there is an alias for this command that should take precedence
		$parts = explode(" ", $message);
		// Only consider aliases like "raid add" and not "raid"
		while (count($parts) > 1) {
			$command = join(" ", $parts);
			$handler = $this->commands[$channel][$command] ?? null;
			if ($handler instanceof CommandHandler) {
				return $handler;
			}
			array_pop($parts);
		}
		// Check if a subcommands for this exists
		if (isset($this->subcommandManager->subcommands[$cmd])) {
			foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
				if ($row->type === $channel && preg_match("/^{$row->cmd}$/si", $message)) {
					return new CommandHandler($row->file, $row->admin);
				}
			}
		}
		return $this->commands[$channel][$cmd] ?? null;
	}

	public function isCommandActive(string $cmd, string $channel): bool {
		$parts = explode(" ", $cmd, 2);
		if (count($parts) === 1) {
			return isset($this->commands[$channel][$cmd]);
		}
		if (isset($this->subcommandManager->subcommands[$parts[0]])) {
			foreach ($this->subcommandManager->subcommands[$parts[0]] as $row) {
				if ($row->type === $channel && $row->cmd === $cmd) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the help text for a command
	 *
	 * @return string|string[] The help text as one or more pages
	 */
	public function getHelpForCommand(string $cmd, string $channel, string $sender) {
		$results = $this->get($cmd, $channel);
		$result = $results[0];

		if (isset($result->help) && $result->help !== '') {
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
	 * @return string[]|bool true if there is no regexp defined, false if it didn't match, otherwise an array with the matched results
	 */
	public function checkMatches(object $instance, string $method, string $message) {
		try {
			$reflectedMethod = new ReflectionAnnotatedMethod($instance, $method);
		} catch (ReflectionException $e) {
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
		}
		return true;
	}

	/**
	 * Get all stored regular expression Matches for a function
	 *
	 * @return string[]
	 */
	public function retrieveRegexes(ReflectionAnnotatedMethod $reflectedMethod): array {
		$regexes = [];
		if ($reflectedMethod->hasAnnotation('Matches')) {
			foreach ($reflectedMethod->getAllAnnotations('Matches') as $annotation) {
				$regexes []= $annotation->value;
			}
		}
		return $regexes;
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(access-denied)";
	}
}
