<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use ReflectionException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\CmdCfg,
	DBSchema\CmdPermSetMapping,
	DBSchema\CmdPermission,
	DBSchema\CmdPermissionSet,
	DBSchema\CommandSearchResult,
	Modules\BAN\BanController,
	Modules\CONFIG\CommandSearchController,
	Modules\LIMITS\LimitsController,
	Modules\USAGE\UsageController,
	ParamClass\Base,
	Routing\RoutableMessage,
	Routing\Source,
};

#[
	NCA\Instance,
	NCA\ProvidesEvent("command(forbidden)"),
	NCA\ProvidesEvent("command(success)"),
	NCA\ProvidesEvent("command(unknown)"),
	NCA\ProvidesEvent("command(help)"),
	NCA\ProvidesEvent("command(error)")
]
class CommandManager implements MessageEmitter {
	public const DB_TABLE = "cmdcfg_<myname>";
	public const DB_TABLE_PERMS = "cmd_permission_<myname>";
	public const DB_TABLE_PERM_SET = "cmd_permission_set_<myname>";
	public const DB_TABLE_MAPPING = "cmd_permission_set_mapping_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public HelpManager $helpManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public CommandSearchController $commandSearchController;

	#[NCA\Inject]
	public UsageController $usageController;

	#[NCA\Inject]
	public LimitsController $limitsController;

	#[NCA\Inject]
	public BanController $banController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<string,array<string,CommandHandler>> $commands */
	public array $commands;

	/** @var array<string,CmdPermission> */
	private array $cmdDefaultPermissions = [];

	/** @var CmdPermSetMapping[] */
	private array $permSetMappings = [];

	/** @var array<string,bool> */
	private array $sources = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->loadPermsetMappings();
		$this->messageHub->registerMessageEmitter($this);
	}

	public function loadPermsetMappings(): void {
		$query = $this->db->table(self::DB_TABLE_MAPPING);
		$this->permSetMappings = $query->orderByDesc($query->colFunc("LENGTH", "source"))
			->asObj(CmdPermSetMapping::class)
			->toArray();
	}

	/** Register a source mask to be used as command source */
	public function registerSource(string $source): bool {
		$source = strtolower($source);
		if (isset($this->sources[$source])) {
			return false;
		}
		$this->sources[$source] = true;
		return true;
	}

	/** Unregister a source mask to be used as command source */
	public function unregisterSource(string $source): bool {
		$source = strtolower($source);
		if (!isset($this->sources[$source])) {
			return false;
		}
		unset($this->sources[$source]);
		return true;
	}

	/** @return string[] */
	public function getSources(): array {
		return array_keys($this->sources);
	}

	/**
	 * Registers a command
	 * @param string   $module        The module that wants to register a new command
	 * @param string   $filename      A comma-separated list of "classname.method" handling $command
	 * @param string   $command       The command to be registered
	 * @param string   $accessLevelStr The required access level to call this comnand. Valid values are:
	 *                                "raidleader", "moderator", "administrator", "none", "superadmin", "admin"
	 *                                "mod", "guild", "member", "rl", "guest", "all"
	 * @param string   $description   A short description what this command is for
	 * @param string   $help          The optional name of file with extended information (without .txt)
	 * @param int|null $defaultStatus The default state of this command:
	 *                                1 (enabled), 0 (disabled) or null (use default value as configured)
	 */
	public function register(string $module, string $filename, string $command, string $accessLevelStr, string $description, ?string $help='', ?int $defaultStatus=null): void {
		$command = strtolower($command);
		$module = strtoupper($module);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevelStr);

		if (empty($filename)) {
			$this->logger->error("Error registering $module:command($command).  Handler is blank.");
			return;
		}

		foreach (explode(',', $filename) as $handler) {
			$name = explode(".", $handler)[0];
			if (!Registry::instanceExists($name)) {
				$this->logger->error("Error registering method '$handler' for command '$command'.  Could not find instance '$name'.");
				return;
			}
		}

		if (!empty($help)) {
			$help = $this->helpManager->checkForHelpFile($module, $help);
		}

		if ($defaultStatus === null) {
			if ($this->config->defaultModuleStatus) {
				$status = 1;
			} else {
				$status = 0;
			}
		} else {
			$status = $defaultStatus;
		}

		$this->logger->info("Adding Command to list:($command) File:($filename)");
		$defaultPerms = new CmdPermission();
		$defaultPerms->access_level = $accessLevel;
		$defaultPerms->enabled = (bool)$status;
		$defaultPerms->cmd = $command;
		$defaultPerms->name = "default";
		$this->cmdDefaultPermissions[$command] = $defaultPerms;
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
					],
					["cmd"],
					["module", "verify", "file", "description", "help"]
				);
		} catch (SQLException $e) {
			$this->logger->error("Error registering method '$handler' for command '$command': " . $e->getMessage(), ["exception" => $e]);
		}
		$permSets = $this->db->table(self::DB_TABLE_PERM_SET)
			->select("name")->pluckAs("name", "string");
		foreach ($permSets as $permSet) {
			$this->logger->info("Adding permissions to command $command");
			$this->db->table(self::DB_TABLE_PERMS)
				->insertOrIgnore(
					[
						"name" => $permSet,
						"access_level" => $accessLevel,
						"cmd" => $command,
						"enabled" => (bool)$status,
					]
				);
		}
	}

	/**
	 * Activates a command
	 * @param string $channel     The name of the channel  where this command should be activated:
	 *                            "msg", "priv" or "guild"
	 * @param string $filename    A comma-separated list of class.method which will handle the command
	 * @param string $command     The name of the command
	 * @param string $accessLevel The required access level to use this command:
	 *                            "raidleader", "moderator", "administrator", "none", "superadmin", "admin"
	 *                            "mod", "guild", "member", "rl", "all"
	 * @return void
	 */
	public function activate(string $channel, string $filename, string $command, string $accessLevel='all'): void {
		$command = strtolower($command);
		$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		$channel = strtolower($channel);

		$this->logger->info("Activate Command $command (Access Level $accessLevel, File $filename, Channel $channel)");

		foreach (explode(',', $filename) as $handler) {
			[$name, $method] = explode(".", $handler);
			if (!Registry::instanceExists($name)) {
				$this->logger->error("Error activating method $handler for command $command.  Could not find instance '$name'.");
				return;
			}
		}

		$obj = new CommandHandler($filename, $accessLevel);

		$this->commands[$channel][$command] = $obj;
	}

	/**
	 * Deactivates a command
	 * @param string $channel  The name of the channel  where this command should be deactivated:
	 *                         "msg", "priv" or "guild"
	 * @param string $filename A comma-separated list of class.method which will handle the command
	 * @param string $command  The name of the command
	 * @return void
	 */
	public function deactivate(string $channel, string $filename, string $command): void {
		$command = strtolower($command);
		$channel = strtolower($channel);

		$this->logger->info("Deactivate Command:($command) File:($filename) Channel:($channel)");

		unset($this->commands[$channel][$command]);
	}

	/**
	 * update the active/inactive status of a command
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

		/** @var Collection<CmdCfg> */
		$data = $query->asObj(CmdCfg::class);
		if ($data->isEmpty()) {
			return 0;
		}
		$permissionQuery = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->whereIn("cmd", $data->pluck("cmd")->toArray());

		if ($channel !== 'all' && $channel !== '' && $channel !== null) {
			$permissionQuery->where("name", $channel);
		}
		$permissions = $permissionQuery->asObj(CmdPermission::class)
			->groupBy("cmd");
		$data->each(function (CmdCfg $row) use ($permissions): void {
			$row->permissions = $permissions->get($row->cmd, new Collection())
				->keyBy("name")->toArray();
		});

		$update = ["enabled" => (bool)$status];
		if ($admin !== '' && $admin !== null) {
			$update["access_level"] = $admin;
		}

		foreach ($data as $row) {
			foreach ($row->permissions as $permission) {
				if ($permission->enabled) {
					$this->activate($permission->name, $row->file, $row->cmd, $admin??"all");
				} else {
					$this->deactivate($permission->name, $row->file, $row->cmd);
				}
			}
		}

		return $permissionQuery->update($update);
	}

	public function hasChannel(string $channel): bool {
		return $this->db->table(CommandManager::DB_TABLE_PERM_SET)
			->where("name", $channel)
			->exists();
	}

	/** @return Collection<CmdPermissionSet> */
	public function getPermissionSets(bool $addMappings=false): Collection {
		$permSets = $this->db->table(CommandManager::DB_TABLE_PERM_SET)
			->asObj(CmdPermissionSet::class);
		if ($addMappings) {
			$mappings = $this->getPermSetMappings()
				->groupBy("permission_set");
			$permSets->each(function(CmdPermissionSet $set) use ($mappings): void {
				$set->mappings = $mappings->get($set->name, new Collection())->toArray();
			});
		}
		return $permSets;
	}

	/** @return Collection<CmdPermSetMapping> */
	public function getPermSetMappings(): Collection {
		return new Collection($this->permSetMappings);
	}

	/** @return Collection<CmdCfg> */
	public function getAll(bool $includeSubcommands=false): Collection {
		$permissions = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->asObj(CmdPermission::class)
			->groupBy("cmd");
		/** @var Collection<CmdCfg> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIn("cmdevent", $includeSubcommands ? ["cmd", "subcmd"] : ["cmd"])
			->asObj(CmdCfg::class)
			->each(function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy("name")->toArray();
			});
		return $data;
	}

	/** @return Collection<CmdCfg> */
	public function getAllForModule(string $module, bool $includeSubcommands=false): Collection {
		$permissions = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->asObj(CmdPermission::class)
			->groupBy("cmd");
		/** @var Collection<CmdCfg> */
		$data = $this->db->table(self::DB_TABLE)
			->whereIn("cmdevent", $includeSubcommands ? ["cmd", "subcmd"] : ["cmd"])
			->where("module", $module)
			->asObj(CmdCfg::class)
			->each(function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy("name")->toArray();
			});
		return $data;
	}

	/**
	 * Loads the active command into memory and activates them
	 */
	public function loadCommands(): void {
		$this->logger->info("Loading enabled commands");

		$this->getAll()
			->each(function (CmdCfg $row): void {
				foreach ($row->permissions as $channel => $permission) {
					if (!$permission->enabled) {
						continue;
					}
					$this->activate($permission->name, $row->file, $row->cmd, $permission->access_level);
				}
			});
	}

	/**
	 * Get command config for a command
	 * @return null|CmdCfg
	 */
	public function get(string $command, ?string $channel=null): ?CmdCfg {
		$query = $this->db->table(self::DB_TABLE)
			->where("cmd", strtolower($command));
		/** @var ?CmdCfg */
		$cmd = $query->asObj(CmdCfg::class)->first();
		if (!isset($cmd)) {
			return null;
		}
		$permQuery = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->where("cmd", strtolower($command));
		if (isset($channel)) {
			$permQuery->where("name", $channel);
		}
		$cmd->permissions = $permQuery->asObj(CmdPermission::class)
			->keyBy("name")
			->toArray();

		return $cmd;
	}

	public function cmdEnabled(string $command): bool {
		return $this->db->table(CommandManager::DB_TABLE_PERMS)
			->where("cmd", $command)
			->where("enabled", true)
			->exists();
	}

	public function cmdExecutable(string $command, string $sender, ?string $channel=null): bool {
		$permissionQuery = $this->db->table(CommandManager::DB_TABLE_PERMS)
			->where("cmd", $command)
			->where("enabled", true);
		if (isset($channel)) {
			$permissionQuery->where("name", $channel);
		}
		/** @var Collection<CmdPermission> */
		$permissions = $permissionQuery->asObj(CmdPermission::class);
		foreach ($permissions as $permission) {
			if ($this->accessManager->checkAccess($sender, $permission->access_level)) {
				return true;
			}
		}
		return false;
	}

	public function processCmd(CmdContext $context): void {
		$cmd = explode(' ', $context->message, 2)[0];
		$cmd = strtolower($cmd);

		if ($this->limitsController->isIgnored($context->char->name)) {
			return;
		}
		$commandHandler = $this->getActiveCommandHandler($cmd, $context->channel, $context->message);
		$event = new CmdEvent();
		$event->channel = $context->channel;
		$event->cmd = $cmd;
		$event->sender = $context->char->name;
		$event->cmdHandler = $commandHandler;

		// if command doesn't exist
		if ($commandHandler === null) {
			if (isset($context->mapping) && !$context->mapping->feedback) {
				return;
			}

			$cmdNames = $this->commandSearchController
				->findSimilarCommands($cmd, $context->char->name)
				->filter(function (CommandSearchResult $row) use ($context): bool {
					return $row->permissions[$context->channel]->enabled ?? false;
				})->slice(0, 5)
				->pluck("cmd");

			$msg = "Unknown command '{$cmd}'.";
			if ($cmdNames->isNotEmpty()) {
				$msg .= " Did you mean " . $cmdNames->join(", ", " or ") . '?';
			}
			$context->reply($msg);
			$event->type = "command(unknown)";
			$this->eventManager->fireEvent($event);
			return;
		}

		// if the character doesn't have access
		if (!$this->checkAccessLevel($context, $cmd, $commandHandler)) {
			$event->type = "command(forbidden)";
			$this->eventManager->fireEvent($event);
			return;
		}

		try {
			$handler = $this->executeCommandHandler($commandHandler, $context);
			$event->type = "command(success)";

			if ($handler === null) {
				$help = $this->getHelpForCommand($cmd, $context->char->name);
				$context->reply($help);
				$event->type = "command(help)";
				$this->eventManager->fireEvent($event);
			}
		} catch (StopExecutionException $e) {
			$event->type = "command(error)";
			throw $e;
		} catch (SQLException $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$context->reply("There was an SQL error executing your command.");
			$event->type = "command(error)";
		} catch (Throwable $e) {
			$this->logger->error(
				"Error executing '{$context->message}': " . $e->getMessage(),
				["exception" => $e]
			);
			$context->reply("There was an error executing your command: " . $e->getMessage());
			$event->type = "command(error)";
		}
		$this->eventManager->fireEvent($event);

		try {
			// record usage stats (in try/catch block in case there is an error)
			if ($this->settingManager->getBool('record_usage_stats') && isset($handler)) {
				$this->usageController->record($context->channel, $cmd, $context->char->name, $handler);
			}
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
		}
	}

	/**
	 * Check if the person sending a command has the right to
	 * @param CmdContext	$context	The full command context
	 * @param string	$cmd	The name of the command that was requested
	 * @param CommandHandler	$commandHandler	The command handler for this command
	 * @return bool true if allowed to execute, otherwise false
	 */
	public function checkAccessLevel(CmdContext $context, string $cmd, CommandHandler $commandHandler): bool {
		if ($this->accessManager->checkAccess($context->char->name, $commandHandler->admin) === true) {
			return true;
		}
		if ($context->isDM()) {
			$r = new RoutableMessage("Player <highlight>{$context->char->name}<end> was denied access to command <highlight>$cmd<end>.");
			$r->appendPath(new Source(Source::SYSTEM, "access-denied"));
			$this->messageHub->handle($r);
		}

		// if they've disabled feedback for guild or private channel, just return
		if (isset($context->mapping) && !$context->mapping->feedback) {
			return false;
		}

		$context->reply("Error! Access denied.");
		return false;
	}

	public function executeCommandHandler(CommandHandler $commandHandler, CmdContext $context): ?string {
		$successfulHandler = null;

		foreach (explode(',', $commandHandler->file) as $handler) {
			[$name, $method] = explode(".", $handler);
			$instance = Registry::getInstance($name);
			if ($instance === null) {
				$this->logger->error("Could not find instance for name '$name'");
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
						$args = [];
						for ($i = 1; $i < count($params); $i++) {
							$var = $params[$i]->getName();
							if (!$params[$i]->hasType() || !isset($context->args[$var]) || ($context->args[$var] === '' && $params[$i]->allowsNull())) {
								if (!$params[$i]->isVariadic()) {
									$args []= null;
								}
								continue;
							}
							$type = $params[$i]->getType();
							if (!($type instanceof ReflectionNamedType) || (!$type->isBuiltin() && !is_subclass_of($type->getName(), Base::class))) {
								$args []= null;
								continue;
							}
							/** @var ReflectionNamedType $type */
							if (is_array($context->args[$var]) && !$params[$i]->isVariadic()) {
								$context->args[$var] = $context->args[$var][0];
							}
							switch ($type->getName()) {
								case "int":
									foreach ((array)$context->args[$var] as $val) {
										$args []= (int)$val;
									}
									break;
								case "bool":
									foreach ((array)$context->args[$var] as $val) {
										$args []= in_array(strtolower($val), ["yes", "true", "1", "on", "enable", "enabled"]);
									}
									break;
								case "float":
									foreach ((array)$context->args[$var] as $val) {
										$args []= (float)$val;
									}
									break;
								default:
									if (is_subclass_of($type->getName(), Base::class)) {
										$class = $type->getName();
										foreach ((array)$context->args[$var] as $val) {
											/** @psalm-suppress UnsafeInstantiation */
											$args []= new $class($val);
										}
									} else {
										foreach ((array)$context->args[$var] as $val) {
											$args []= $val;
										}
									}
									break;
							}
						}
						$syntaxError = $instance->$method($context, ...$args) === false;
					} else {
						$syntaxError = ($instance->$method($context->message, $context->channel, $context->char->name, $context->sendto, $context->args) === false);
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
				if (isset($row->permissions[$channel]) && preg_match("/^{$row->cmd}$/si", $message)) {
					return new CommandHandler($row->file, $row->permissions[$channel]->access_level);
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
				if (!isset($row->permissions[$channel])) {
					continue;
				}
				if ($row->cmd !== $cmd) {
					continue;
				}
				if ($row->permissions[$channel]->enabled) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the help text for a command
	 * @return string|string[] The help text as one or more pages
	 */
	public function getHelpForCommand(string $cmd, string $sender): string|array {
		$result = $this->get($cmd);
		if (!isset($result)) {
			return "Unknown command '{$cmd}'";
		}

		if (isset($result->help) && $result->help !== '') {
			$blob = \Safe\file_get_contents($result->help);
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
	 * @return string[]|bool|array<string,string[]> true if there is no regexp defined, false if it didn't match, otherwise an array with the matched results
	 */
	public function checkMatches(object $instance, string $method, string $message): array|bool {
		try {
			$reflectedMethod = new ReflectionMethod($instance, $method);
		} catch (ReflectionException $e) {
			// method doesn't exist (probably handled dynamically)
			return true;
		}

		$regexes = $this->retrieveRegexes($reflectedMethod);

		if (count($regexes) > 0) {
			foreach ($regexes as $regex) {
				if (preg_match($regex->match, $message, $arr)) {
					if (isset($regex->variadicMatch)) {
						if (preg_match_all($regex->variadicMatch, $message, $arr2)) {
							$arr = $arr2;
						}
					}
					return $arr;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Get all stored regular expression Matches for a function
	 * @return CommandRegexp[]
	 */
	public function retrieveRegexes(ReflectionMethod $reflectedMethod): array {
		$regexes = [];
		if (count($reflectedMethod->getAttributes(NCA\HandlesCommand::class))) {
			$regexes = $this->getRegexpFromCharClass($reflectedMethod);
		}
		return $regexes;
	}

	protected function getParamRegexp(ReflectionParameter $param, string $comment): ?CommandRegexp {
		if (!$param->hasType()) {
			return null;
		}
		$type = $param->getType();
		if (!($type instanceof ReflectionNamedType)) {
			return null;
		}
		if (!$type->isBuiltin() && !is_subclass_of($type->getName(), Base::class)) {
			return null;
		}
		$varName = $param->getName();
		if ($type->isBuiltin()) {
			$mask = null;
			$constAttrs = $param->getAttributes(NCA\Str::class);
			$regexpAttrs = $param->getAttributes(NCA\Regexp::class);
			if (!empty($constAttrs)) {
				/** @var NCA\Str */
				$constObj = $constAttrs[0]->newInstance();
				$mask = join("|", array_map("preg_quote", $constObj->values));
			} elseif (!empty($regexpAttrs)) {
				/** @var NCA\Regexp */
				$regexpObj = $regexpAttrs[0]->newInstance();
				$mask = $regexpObj->value;
			}
			switch ($type->getName()) {
				case "string":
					$mask ??= ".+";
					$new = "(?<{$varName}>{$mask})";
					break;
				case "int":
					$mask ??= '\d+';
					$new = "(?<{$varName}>{$mask})";
					break;
				case "bool":
					$new = "(?<{$varName}>true|false|yes|no|on|off|enabled?|disabled?)";
					break;
				case "float":
					$mask ??= '\d*\.?\d+';
					$new  = "(?<{$varName}>{$mask})";
					break;
			}
		} else {
			$c1 = [$type->getName(), "getPreRegExp"];
			$c2 = [$type->getName(), "getRegexp"];
			if (is_callable($c1) && is_callable($c2)) {
				$new = "(?:" . $c1() . "(?<{$varName}>" . $c2() . "))";
			}
		}
		if (!isset($new)) {
			return null;
		}
		if (count($param->getAttributes(NCA\SpaceOptional::class))) {
			$regexp = new CommandRegexp("\\s*{$new}");
		} else {
			$regexp = new CommandRegexp("\\s+{$new}");
		}
		if ($param->allowsNull()) {
			if ($param->isVariadic()) {
				$regexp->variadicMatch = $regexp->match;
				$regexp->match = "(?:{$regexp->match})*";
			} else {
				$regexp->match = "(?:{$regexp->match})?";
			}
		} elseif ($param->isVariadic()) {
			$regexp->variadicMatch = $regexp->match;
			$regexp->match = "(?:{$regexp->match})+";
		}
		return $regexp;
	}

	/**
	 * @return CommandRegexp[]
	 */
	public function getRegexpFromCharClass(ReflectionMethod $method): array {
		$params = $method->getParameters();
		if (count($params) === 0
			|| !$params[0]->hasType() ) {
			return [];
		}
		$type = $params[0]->getType();
		if (!($type instanceof ReflectionNamedType)
			|| ($type->getName() !== CmdContext::class)) {
			return [];
		}
		$regexp = [];
		$cmds = $method->getAttributes(NCA\HandlesCommand::class);
		if (count($cmds)) {
			$commands = [];
			foreach ($cmds as $command) {
				/** @var NCA\HandlesCommand */
				$cmdObj = $command->newInstance();
				$commands []= explode(" ", $cmdObj->command)[0];
			}
			if (count($commands) === 1) {
				$regexp = $commands;
			} else {
				$regexp []= "(?:" . join("|", $commands) . ")";
			}
		}
		$comment = $method->getDocComment();
		if ($comment === false) {
			$comment = "";
		}
		$variadic = null;
		for ($i = 1; $i < count($params); $i++) {
			$regex = $this->getParamRegexp($params[$i], $comment);
			if ($regex === null) {
				return [];
			}
			if (isset($regex->variadicMatch)) {
				$variadic = ["(?:^", ...$regexp, "|\\G)"];
				$variadic []= $regex->variadicMatch;
			}
			$regexp []= $regex->match;
		}
		$regexp = new CommandRegexp(chr(1) . "^" . join("", $regexp) . '$' . chr(1) . "is");
		if (isset($variadic)) {
			$regexp->variadicMatch = chr(1) . join("", $variadic) . chr(1) . "is";
		}
		$result = [$regexp];
		return $result;
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(access-denied)";
	}

	public function getPermissionSet(string $name, bool $addMappings=false): ?CmdPermissionSet {
		/** @var ?CmdPermissionSet */
		$permSet = $this->db->table(self::DB_TABLE_PERM_SET)
			->where("name", $name)
			->asObj(CmdPermissionSet::class)
			->first();
		if (isset($permSet) && $addMappings) {
			$permSet->mappings = $this->getPermSetMappings()
				->where("permission_set", $name)
				->values()
				->toArray();
		}
		return $permSet;
	}

	public function getDefaultPermissions(string $cmd): ?CmdPermission {
		return $this->cmdDefaultPermissions[$cmd] ?? null;
	}

	private function insertPermissionSet(string $name, string $letter, CmdPermission ...$perms): void {
		$letter = strtoupper($letter);
		$name = strtolower($name);
		if (strlen($letter) !== 1) {
			throw new InvalidArgumentException("The letter of a permission set must be exactly 1 character long.");
		}
		if ($this->db->table(self::DB_TABLE_PERM_SET)->where("name", $name)->exists()) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> already exists.");
		}
		if ($this->db->table(self::DB_TABLE_PERM_SET)->where("letter", $letter)->exists()) {
			throw new InvalidArgumentException("A permission set with the letter <highlight>{$letter}<end> already exists.");
		}
		$inserts = [];
		foreach ($perms as $perm) {
			unset($perm->id);
			$perm->name = $name;
			$inserts []= (array)$perm;
		}
		$this->db->beginTransaction();
		try {
			$this->db->table(self::DB_TABLE_PERM_SET)
				->insert(["name" => $name, "letter" => $letter]);
			$this->db->table(self::DB_TABLE_PERMS)
				->chunkInsert($inserts);
		} catch (Exception $e) {
			$this->db->rollback();
			throw new Exception("There was an unknown error saving the new permission set.", 0, $e);
		}
		$this->db->commit();
		$this->loadCommands();
		$this->subcommandManager->loadSubcommands();
	}

	/**
	 * Create a new set of permissions based on the default permissions of the bot
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws Exception on unknown errors, like SQL
	 */
	public function createPermissionSet(string $name, string $letter): void {
		$allCmds = $this->getAll(true);
		$perms = [];
		foreach ($allCmds as $cmd) {
			$cmdPerms = ($cmd->cmdevent === "cmd")
				? $this->getDefaultPermissions($cmd->cmd)
				: $this->subcommandManager->getDefaultPermissions($cmd->cmd);
			if (!isset($cmdPerms)) {
				throw new Exception("There are no default permissions registered for {$cmd->cmd}.");
			}
			$cmdPerms->name = $name;
			$perms []= $cmdPerms;
		}
		$this->insertPermissionSet($name, $letter, ...$perms);
	}

	/**
	 * Change a permission set
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws Exception on unknown errors, like SQL
	 */
	public function changePermissionSet(string $name, CmdPermissionSet $data): void {
		$old = $this->getPermissionSet($name);
		if (!isset($old)) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> does not exist.");
		}
		if ($data->name !== $old->name) {
			$newNameExists = $this->db->table(self::DB_TABLE_PERM_SET)
				->where("name", $data->name)->exists();
			if ($newNameExists) {
				throw new InvalidArgumentException(
					"A permission set <highlight>{$data->name}<end> already exists."
				);
			}
		}
		if ($data->letter !== $old->letter) {
			$newLetterExists = $this->db->table(self::DB_TABLE_PERM_SET)
				->where("letter", $data->letter)->exists();
			if ($newLetterExists) {
				throw new InvalidArgumentException(
					"A permission set with the letter <highlight>{$data->letter}<end> already exists."
				);
			}
		}
		$this->db->beginTransaction();
		try {
			$this->db->table(self::DB_TABLE_PERM_SET)
				->where("name", $name)
				->update([
					"name" => $data->name,
					"letter" => $data->letter
				]);
			if ($data->name !== $old->name) {
				$this->db->table(self::DB_TABLE_MAPPING)
					->where("permission_set", $name)
					->update(["permission_et" => $data->name]);
				$this->db->table(self::DB_TABLE_PERMS)
					->where("name", $name)
					->update(["name" => $data->name]);
			}
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();
		$this->loadPermsetMappings();
		$this->loadCommands();
		$this->subcommandManager->loadSubcommands();
	}

	/**
	 * Create a new set of permissions based another set
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws Exception on unknown errors, like SQL
	 */
	public function clonePermissionSet(string $oldName, string $name, string $letter): void {
		$perms = $this->db->table(self::DB_TABLE_PERMS)
			->where("name", $oldName)
			->asObj(CmdPermission::class)
			->toArray();
		$this->insertPermissionSet($name, $letter, ...$perms);
	}

	/**
	 * Delete a permission set
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws Exception on unknown errors, like SQL
	 */
	public function deletePermissionSet(string $name): void {
		$name = strtolower($name);
		if (!$this->db->table(self::DB_TABLE_PERM_SET)->where("name", $name)->exists()) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> does not exist.");
		}
		if (count($usedBy = $this->getSourcesForPermsetName($name)) > 0) {
			throw new InvalidArgumentException(
				"The permission set <highlight>{$name}<end> is still assigned to <highlight>".
				(new Collection($usedBy))->join("<end>, <highlight>", "<end> and <highlight>").
				"<end>."
			);
		}
		$this->db->beginTransaction();
		try {
			$this->db->table(self::DB_TABLE_PERMS)
				->where("name", $name)
				->delete();
			$this->db->table(self::DB_TABLE_PERM_SET)
				->where("name", $name)
				->delete();
		} catch (Exception $e) {
			$this->db->rollback();
			throw new Exception("There was an unknown error deleting that permission set.", 0, $e);
		}
		$this->db->commit();
		unset($this->commands[$name]);
		$this->subcommandManager->loadSubcommands();
	}

	/**
	 * Delete a permission set mapping
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws Exception on unknown errors, like SQL
	 */
	public function deletePermissionSetMapping(string $source): bool {
		$source = strtolower($source);
		if ($this->getPermSetMappings()->where("source", $source)->isEmpty()) {
			return false;
		}
		$numDeleted = $this->db->table(self::DB_TABLE_MAPPING)
			->where("source", $source)
			->delete();
		if ($numDeleted === 0) {
			return false;
		}
		$this->loadPermsetMappings();
		return true;
	}

	/**
	 * Try to determine to which permission set a routing source maps
	 * @param string $source Name of the source, e.g. aopriv(Tester)
	 * @return null|CmdPermSetMapping The mapping of the permission set, or null if no execution intended
	 */
	public function getPermsetMapForSource(string $source): ?CmdPermSetMapping {
		foreach ($this->permSetMappings as $map) {
			if (fnmatch($map->source, $source, FNM_CASEFOLD)) {
				return $map;
			}
		}
		return null;
	}

	/**
	 * Get the names of all sources using the permission set $name
	 * @param string $name Name of the permission set
	 * @return string[] A list of all sources mapping to this
	 */
	public function getSourcesForPermsetName(string $name): array {
		$name = strtolower($name);
		$result = [];
		foreach ($this->permSetMappings as $map) {
			if ($map->permission_set === $name) {
				$result []= $map->source;
			}
		}
		return $result;
	}

	/**
	 * Check the message in $context for a valid command and execute it in the proper channel
	 */
	public function checkAndHandleCmd(CmdContext $context): bool {
		if (!isset($context->source)) {
			return false;
		}
		$this->logger->notice("Received msg from {$context->source}");
		$cmdMap = $this->getPermsetMapForSource($context->source);
		if (!isset($cmdMap)) {
			return false;
		}
		if (strncmp($context->message, $cmdMap->symbol, strlen($cmdMap->symbol)) === 0) {
			$context->message = substr($context->message, strlen($cmdMap->symbol));
		} elseif (!$cmdMap->symbol_optional) {
			return false;
		}

		$context->channel = $cmdMap->permission_set;
		$context->mapping = $cmdMap;
		if (!isset($context->char->id)) {
			$this->processCmd($context);
			return true;
		}
		$this->banController->handleBan(
			$context->char->id,
			function (int $senderId, CmdContext $context): void {
				$this->processCmd($context);
			},
			null,
			$context
		);
		return true;
	}
}
