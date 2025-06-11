<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Sync\KeyedMutex;
use Exception;
use Generator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\CmdCfg,
	DBSchema\CmdPermSetMapping,
	DBSchema\CmdPermission,
	DBSchema\CmdPermissionSet,
	DBSchema\CommandSearchResult,
	DBSchema\ExtCmdPermissionSet,
	Events\ErrorCmdEvent,
	Events\ForbiddenCmdEvent,
	Events\HelpCmdEvent,
	Events\SuccessCmdEvent,
	Events\UnknownCmdEvent,
	Exceptions\SQLException,
	Exceptions\StopExecutionException,
	Exceptions\UserException,
	Modules\BAN\BanController,
	Modules\CONFIG\CommandSearchController,
	Modules\HELP\HelpController,
	Modules\LIMITS\LimitsController,
	Modules\PREFERENCES\Preferences,
	Modules\USAGE\UsageController,
	ParamClass\Base,
	Routing\RoutableMessage,
	Routing\Source,
	Types\AccessLevel,
	Types\EnumExampleInterface,
	Types\EnumParameterInterface,
	Types\MessageEmitter,
	Types\ParamAttribute,
	Types\Status,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use Revolt\EventLoop;
use Throwable;

/** The management class for handling commands, adding and removing them */
#[NCA\Instance]
class CommandManager implements MessageEmitter {
	private const DIRECT = 1;
	private const PUBLIC = 2;

	/** @var array<string,array<string,CommandHandler>> */
	public array $commands;

	/** Reply to send when a non-member tries to access the bot */
	#[NCA\Setting\Text]
	public string $noMemberErrorMsg = 'I only listen to members of this bot.';

	/** Reply to send when the access-level is too low for a command */
	#[NCA\Setting\Text]
	public string $accessDeniedErrorMsg = 'Error! Access denied.';

	/** Give 'Unknown command'-message on disabled/non-existing commands */
	#[NCA\Setting\Number(
		options: [
			'on all messages' => self::DIRECT | self::PUBLIC,
			'on direct messages only' => self::DIRECT,
			'on public messages only' => self::PUBLIC,
		]
	)]
	public int $errorOnUnknownCommand = self::DIRECT | self::PUBLIC;

	/** Show command suggestions with 'Unknown command'-message */
	#[NCA\Setting\Boolean]
	public bool $suggestionsOnUnknownCommand = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private HelpController $helpController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private Preferences $preferences;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private SubcommandManager $subcommandManager;

	#[NCA\Inject]
	private CommandSearchController $commandSearchController;

	#[NCA\Inject]
	private UsageController $usageController;

	#[NCA\Inject]
	private LimitsController $limitsController;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private KeyedMutex $mutex;

	/** @var array<string,CmdPermission> */
	private array $cmdDefaultPermissions = [];

	/** @var list<CmdPermSetMapping> */
	private array $permSetMappings = [];

	/**
	 * List of Source-types allowed as command sources (`'aoorg'`, `'discordpriv'`, etc.)
	 * Stored as `name => true` for quicker access.
	 *
	 * @var array<string,true>
	 */
	private array $sources = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->loadPermsetMappings();
		$this->messageHub->registerMessageEmitter($this);
	}

	/** Load all permission set mappings into our cache */
	public function loadPermsetMappings(): void {
		$query = $this->db->table(CmdPermSetMapping::getTable());
		$this->permSetMappings = $query->orderByDesc($query->raw($query->colFunc('LENGTH', 'source')))
			->asObjArr(CmdPermSetMapping::class);
	}

	/**
	 * Register a source mask to be used as command source
	 *
	 * @return bool `true` if added, `false` if already registered
	 */
	public function registerSource(string $source): bool {
		$source = strtolower($source);
		if (isset($this->sources[$source])) {
			return false;
		}
		$this->sources[$source] = true;
		return true;
	}

	/**
	 * Unregister a source mask to be used as command source
	 *
	 * @return bool `true` if removed, `false` if already removed
	 */
	public function unregisterSource(string $source): bool {
		$source = strtolower($source);
		if (!isset($this->sources[$source])) {
			return false;
		}
		unset($this->sources[$source]);
		return true;
	}

	/**
	 * Get a list of all allowed command source masks
	 *
	 * @return list<string>
	 */
	public function getSources(): array {
		return array_keys($this->sources);
	}

	/**
	 * Registers a command
	 *
	 * @param string      $module        The module that wants to register a new command
	 * @param string      $filename      A comma-separated list of "classname.method" handling $command
	 * @param string      $command       The command to be registered
	 * @param AccessLevel $accessLevel   The minimum required access level to call this command.
	 * @param string      $description   A short description what this command is for
	 * @param null|Status $defaultStatus The default state of this command:
	 *                                   Enabled, Disabled or null (use default value as configured)
	 */
	public function register(string $module, string $filename, string $command, AccessLevel $accessLevel, string $description, ?Status $defaultStatus=null): void {
		$command = strtolower($command);
		$module = strtoupper($module);

		if ($filename === '') {
			$this->logger->error('Error registering {module}:command({command}). Handler is blank.', [
				'module' => $module,
				'command' => $command,
			]);
			return;
		}

		foreach (explode(',', $filename) as $handler) {
			$name = explode('.', $handler)[0];
			if (!Registry::hasInstance($name)) {
				$this->logger->error("Error registering method '{method}' for command '{command}'.  Could not find instance '{instance}'.", [
					'method' => $handler,
					'command' => $command,
					'instance' => $name,
				]);
				return;
			}
		}

		$status = $defaultStatus ?? $this->config->general->defaultModuleStatus;

		$this->logger->info('Adding Command to list:({command}) File:({file})', [
			'command' => $command,
			'file' => $filename,
		]);
		$defaultPerms = new CmdPermission(
			access_level: $accessLevel,
			enabled: $status === Status::Enabled,
			cmd: $command,
			permission_set: 'default',
		);
		$this->cmdDefaultPermissions[$command] = $defaultPerms;
		try {
			$this->db->upsert(new CmdCfg(
				module: $module,
				verify: 1,
				file: $filename,
				description: $description,
				cmd: $command,
				cmdevent: 'cmd',
			));
		} catch (SQLException $e) {
			$this->logger->error("Error registering method '{method}' for command '{command}': {error}", [
				'method' => $filename,
				'command' => $command,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		$permSets = $this->db->table(CmdPermissionSet::getTable())
			->select('name')->pluckStrings('name');
		foreach ($permSets as $permSet) {
			$this->logger->info('Adding permissions to command {command}', [
				'command' => $command,
			]);
			$this->db->table(CmdPermission::getTable())
				->insertOrIgnore(
					[
						'permission_set' => $permSet,
						'access_level' => $accessLevel,
						'cmd' => $command,
						'enabled' => $status === Status::Enabled,
						'id' => Uuid::uuid7(),
					]
				);
		}
	}

	/**
	 * Activates a command
	 *
	 * @param string      $permissionSet The name of the channel  where this command should be activated:
	 *                                   "msg", "priv" or "guild"
	 * @param string      $filename      A comma-separated list of class.method which will handle the command
	 * @param string      $command       The name of the command
	 * @param AccessLevel $accessLevel   The required access level to use this command
	 */
	public function activate(string $permissionSet, string $filename, string $command, AccessLevel $accessLevel=AccessLevel::All): void {
		$command = strtolower($command);
		$permissionSet = strtolower($permissionSet);

		$this->logger->info('Activate Command {command} (Access Level {access_level}, File {file}, PermissionSet {permission_set})', [
			'command' => $command,
			'access_level' => $accessLevel,
			'file' => $filename,
			'permission_set' => $permissionSet,
		]);

		foreach (explode(',', $filename) as $handler) {
			[$name, $method] = explode('.', $handler);
			if (!Registry::hasInstance($name)) {
				$this->logger->error("Error activating method {method} for command {command}.  Could not find instance '{instance}'.", [
					'method' => $handler,
					'command' => $command,
					'instance' => $name,
				]);
				return;
			}
		}

		$obj = new CommandHandler($accessLevel, ...explode(',', $filename));

		$this->commands[$permissionSet][$command] = $obj;
	}

	/**
	 * Deactivates a command
	 *
	 * @param string $permissionSet The permission set for which this command should be deactivated:
	 *                              "msg", "priv", "guild" or whatever custom ones are used
	 * @param string $filename      A comma-separated list of class.method which will handle the command
	 * @param string $command       The name of the command
	 */
	public function deactivate(string $permissionSet, string $filename, string $command): void {
		$command = strtolower($command);
		$permissionSet = strtolower($permissionSet);

		$this->logger->info('Deactivate Command:({command}) File:({file}) Permission Set:({permission_set})', [
			'command' => $command,
			'file' => $filename,
			'permission_set' => $permissionSet,
		]);

		unset($this->commands[$permissionSet][$command]);
	}

	/**
	 * update the active/inactive status of a command
	 *
	 * @param ?string      $permissionSet The name of the permission set for which this
	 *                                    command's status should be changed:
	 *                                    "msg", "priv", "guild" or any other custom one
	 * @param ?string      $cmd           The name of the command
	 * @param ?string      $module        The name of the module of the command
	 * @param Status       $status        The new status: enabled or disabled
	 * @param ?AccessLevel $accessLevel   The access level for which to update the status
	 */
	public function updateStatus(
		?string $permissionSet,
		?string $cmd,
		?string $module,
		Status $status,
		?AccessLevel $accessLevel
	): int {
		$query = $this->db->table(CmdCfg::getTable())
			->where('cmdevent', 'cmd');
		if ($module !== '' && $module !== null) {
			$query->where('module', $module);
		}
		if ($cmd !== '' && $cmd !== null) {
			$query->where('cmd', $cmd);
		}

		$data = $query->asObj(CmdCfg::class);
		if ($data->isEmpty()) {
			return 0;
		}
		$permissionQuery = $this->db->table(CmdPermission::getTable())
			->whereIn('cmd', $data->pluckStrings('cmd')->toList());

		if ($permissionSet !== 'all' && $permissionSet !== '' && $permissionSet !== null) {
			$permissionQuery->where('permission_set', $permissionSet);
		}
		$permissions = $permissionQuery->asObj(CmdPermission::class)
			->groupBy('cmd');
		$data->each(static function (CmdCfg $row) use ($permissions): void {
			$row->permissions = $permissions->get($row->cmd, new Collection())
				->keyBy('permission_set')->toArray();
		});

		$update = ['enabled' => (bool)$status->value];
		if (isset($accessLevel)) {
			$update['access_level'] = $accessLevel;
		}

		foreach ($data as $row) {
			foreach ($row->permissions as $permission) {
				if ($permission->enabled) {
					$this->activate($permission->permission_set, $row->file, $row->cmd, $accessLevel??AccessLevel::All);
				} else {
					$this->deactivate($permission->permission_set, $row->file, $row->cmd);
				}
			}
		}

		return $permissionQuery->update($update);
	}

	/** Check if a permission set with the given name exists */
	public function hasPermissionSet(string $name): bool {
		return $this->db->table(CmdPermissionSet::getTable())
			->where('name', $name)
			->exists();
	}

	/** @return Collection<int,CmdPermissionSet> */
	public function getPermissionSets(): Collection {
		$permSets = $this->db->table(CmdPermissionSet::getTable())
			->asObj(CmdPermissionSet::class);
		return $permSets;
	}

	/** @return Collection<int,ExtCmdPermissionSet> */
	public function getExtPermissionSets(): Collection {
		$permSets = $this->db->table(CmdPermissionSet::getTable())
			->asObj(ExtCmdPermissionSet::class);
		$mappings = $this->getPermSetMappings()
			->groupBy('permission_set');
		$permSets->each(static function (ExtCmdPermissionSet $set) use ($mappings): void {
			$set->mappings = $mappings->get($set->name, new Collection())->toList();
		});
		return $permSets;
	}

	/** @return Collection<int,CmdPermSetMapping> */
	public function getPermSetMappings(): Collection {
		/** @var Collection<int,CmdPermSetMapping> */
		$result = collect($this->permSetMappings);
		return $result;
	}

	/**
	 * Get all defined commands
	 *
	 * @param bool $includeSubcommands Also include sub-commands
	 *
	 * @return Collection<int,CmdCfg>
	 */
	public function getAll(bool $includeSubcommands=false): Collection {
		/** @var Collection<string,Collection<int,CmdPermission>> */
		$permissions = $this->db->table(CmdPermission::getTable())
			->asObj(CmdPermission::class)
			->groupBy('cmd');

		$data = $this->db->table(CmdCfg::getTable())
			->whereIn('cmdevent', $includeSubcommands ? ['cmd', 'subcmd'] : ['cmd'])
			->asObj(CmdCfg::class)
			->each(static function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy('permission_set')->toArray();
			});
		return $data;
	}

	/**
	 * Get all defined commands for a single module
	 *
	 * @param string $module             The name of the module
	 * @param bool   $includeSubcommands Also include sub-commands
	 *
	 * @return Collection<int,CmdCfg>
	 */
	public function getAllForModule(string $module, bool $includeSubcommands=false): Collection {
		/** @var Collection<string,Collection<int,CmdPermission>> */
		$permissions = $this->db->table(CmdPermission::getTable())
			->asObj(CmdPermission::class)
			->groupBy('cmd');

		$data = $this->db->table(CmdCfg::getTable())
			->whereIn('cmdevent', $includeSubcommands ? ['cmd', 'subcmd'] : ['cmd'])
			->where('module', $module)
			->asObj(CmdCfg::class)
			->each(static function (CmdCfg $row) use ($permissions): void {
				$row->permissions = $permissions->get($row->cmd, new Collection())
					->keyBy('permission_set')->toArray();
			});
		return $data;
	}

	/** Loads the active command into memory and activates them */
	public function loadCommands(): void {
		$this->logger->info('Loading enabled commands');

		$this->getAll()->each($this->activateCmdCfg(...));
	}

	/** Get command config for a command */
	public function get(string $command, ?string $permissionSet=null): ?CmdCfg {
		$subCmd = $this->subcommandManager->subcommands[$command][$permissionSet]??null;
		if (isset($subCmd)) {
			return $subCmd;
		}
		$query = $this->db->table(CmdCfg::getTable())
			->where('cmd', strtolower($command));

		/** @var ?CmdCfg */
		$cmd = $query->firstObj(CmdCfg::class);
		if (!isset($cmd)) {
			return null;
		}
		$permQuery = $this->db->table(CmdPermission::getTable())
			->where('cmd', strtolower($command));
		if (isset($permissionSet)) {
			$permQuery->where('permission_set', $permissionSet);
		}
		$cmd->permissions = $permQuery->asObj(CmdPermission::class)
			->keyBy('permission_set')
			->toArray();

		return $cmd;
	}

	/** Check if a given command is enabled */
	public function cmdEnabled(string $command): bool {
		return $this->db->table(CmdPermission::getTable())
			->where('cmd', $command)
			->where('enabled', true)
			->exists();
	}

	/**
	 * Check if a given command can be executed by the given character
	 *
	 * @param string      $command       Name of the command
	 * @param string      $sender        The character to get the access level from
	 * @param null|string $permissionSet The permission set to check
	 */
	public function cmdExecutable(string $command, string $sender, ?string $permissionSet=null): bool {
		$permissionQuery = $this->db->table(CmdPermission::getTable())
			->where('cmd', $command)
			->where('enabled', true);
		if (isset($permissionSet)) {
			$permissionQuery->where('permission_set', $permissionSet);
		}

		$permissions = $permissionQuery->asObj(CmdPermission::class);
		foreach ($permissions as $permission) {
			if ($this->accessManager->checkAccess($sender, $permission->access_level)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a character in the given command context is allowed to call a command handler
	 *
	 * @param CmdContext $context The command context which to check
	 * @param string     $handler The command handler in the format
	 *                            `<class name>`.`<method name>`
	 */
	public function canCallHandler(CmdContext $context, string $handler): bool {
		if ($handler === CommandAlias::ALIAS_HANDLER) {
			return true;
		}
		[$name, $method] = explode('.', $handler);
		[$method, $line] = explode(':', $method);
		$instance = Registry::tryGetInstance($name);
		if ($instance === null) {
			$this->logger->error("Could not find instance for name '{instance}'", [
				'instance' => $name,
			]);
			return false;
		}
		// Check if this matches any command regular expression
		$arr = $this->checkMatches($instance, $method, $context->message);
		if ($arr === false) {
			return false;
		}
		try {
			$reflectedMethod = new ReflectionMethod($instance, $method);
		} catch (ReflectionException $e) {
			// method doesn't exist (probably handled dynamically)
			return false;
		}
		$handlesAttrs = $reflectedMethod->getAttributes(NCA\HandlesCommand::class, ReflectionAttribute::IS_INSTANCEOF);
		if (!count($handlesAttrs)) {
			return false;
		}
		foreach ($handlesAttrs as $handlesAttr) {
			$handlesCmdObj = $handlesAttr->newInstance();
			if ($handlesCmdObj instanceof NCA\HandlesAllCommands) {
				return true;
			}

			$baseCmd = explode(' ', $handlesCmdObj->command)[0];
			if ($baseCmd !== strtolower(explode(' ', $context->message)[0])) {
				continue;
			}
			$cmdCfg = $this->get($handlesCmdObj->command, $context->permissionSet);
			if (!isset($cmdCfg) || !isset($cmdCfg->permissions[$context->permissionSet])) {
				continue;
			}
			if (!$this->accessManager->checkAccess($context->char->name, $cmdCfg->permissions[$context->permissionSet]->access_level) === true) {
				continue;
			}
			return true;
		}
		return false;
	}

	/** Check if the character in the current context could run $command */
	public function couldRunCommand(CmdContext $context, string $command): bool {
		$context = clone $context;
		$context->message = $command;
		if (!isset($context->permissionSet)) {
			return false;
		}
		$cmd = explode(' ', $command)[0];
		$commandHandler = $this->getActiveCommandHandler($cmd, $context->permissionSet, $command);
		if (!isset($commandHandler)) {
			return false;
		}
		// Remove all handler we are not allowed to call or which don't match
		$commandHandler->files = array_values(array_filter(
			$commandHandler->files,
			function (string $handler) use ($context): bool {
				return $this->canCallHandler($context, $handler);
			}
		));
		return count($commandHandler->files) > 0;
	}

	/**
	 * Execute the command represented by the given command context
	 *
	 * @throws StopExecutionException if no further processing is allowed
	 */
	public function processCmd(CmdContext $context): void {
		EventLoop::queue($this->syncProcessCmd(...), $context);
	}

	public function syncProcessCmd(CmdContext $context): void {
		$cmd = explode(' ', $context->message, 2)[0];
		$cmd = strtolower($cmd);

		if ($this->limitsController->isIgnored($context->char->name)) {
			return;
		}
		if (!isset($context->permissionSet)) {
			return;
		}
		$commandHandler = $this->getActiveCommandHandler($cmd, $context->permissionSet, $context->message);

		// if command doesn't exist
		if ($commandHandler === null) {
			if (isset($context->mapping) && !$context->mapping->feedback) {
				return;
			}

			$cmdNames = new Collection();
			if ($this->suggestionsOnUnknownCommand) {
				$cmdNames = $this->commandSearchController
					->findSimilarCommands($cmd, $context->char->name)
					->filter(static function (CommandSearchResult $row) use ($context): bool {
						return $row->permissions[$context->permissionSet]->enabled ?? false;
					})->slice(0, 5)
					->pluckStrings('cmd');
			}

			$msg = "Unknown command '{$cmd}'.";
			if ($cmdNames->isNotEmpty()) {
				$msg .= ' Did you mean ' . $cmdNames->join(', ', ' or ') . '?';
			}
			if (
				$context->isDM() && (($this->errorOnUnknownCommand & self::DIRECT) === self::DIRECT)
				|| !$context->isDM() && (($this->errorOnUnknownCommand & self::PUBLIC) === self::PUBLIC)
			) {
				$context->reply($msg);
			}
			$event = new UnknownCmdEvent(
				channel: $context->permissionSet,
				cmd: $cmd,
				sender: $context->char->name,
			);
			$this->eventManager->dispatch($event);
			return;
		}

		// Remove all handler we are not allowed to call or which don't match
		$commandHandler->files = array_values(array_filter(
			$commandHandler->files,
			function (string $handler) use ($context): bool {
				return $this->canCallHandler($context, $handler);
			}
		));

		// If there are no handlers we have access to and the character doesn't
		// even have access to the main-command: error
		if (!count($commandHandler->files) && !$this->checkAccessLevel($context, $cmd, $commandHandler)) {
			$event = new ForbiddenCmdEvent(
				channel: $context->permissionSet,
				cmd: $cmd,
				sender: $context->char->name,
				cmdHandler: $commandHandler,
			);
			$this->eventManager->dispatch($event);
			return;
		}

		try {
			$handler = $this->executeCommandHandler($commandHandler, $context);

			// No handler found? Display the help
			if ($handler === null) {
				$help = $this->getHelpForCommand($cmd, $context);
				$context->reply($help);
				$event = new HelpCmdEvent(
					channel: $context->permissionSet,
					cmd: $cmd,
					sender: $context->char->name,
					cmdHandler: $commandHandler,
				);
			} else {
				$event = new SuccessCmdEvent(
					channel: $context->permissionSet,
					cmd: $cmd,
					sender: $context->char->name,
					cmdHandler: $commandHandler,
				);
			}
		} catch (StopExecutionException $e) {
			throw $e;
		} catch (SQLException $e) {
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$context->reply('There was an SQL error executing your command.');
			$event = new ErrorCmdEvent(
				channel: $context->permissionSet,
				cmd: $cmd,
				sender: $context->char->name,
				cmdHandler: $commandHandler,
			);
		} catch (Throwable $e) {
			if (!BotRunner::getArguments()->testRun) {
				$this->logger->error("Error executing '{command}': {error}", [
					'command' => $context->message,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
			$context->reply('There was an error executing your command: ' . $e->getMessage());
			$event = new ErrorCmdEvent(
				channel: $context->permissionSet,
				cmd: $cmd,
				sender: $context->char->name,
				cmdHandler: $commandHandler,
			);
		}
		$this->eventManager->dispatch($event);

		try {
			// record usage stats (in try/catch block in case there is an error)
			if ($this->usageController->recordUsageStats && isset($handler)) {
				$this->usageController->record($context->permissionSet, $cmd, $context->char->name, $handler);
			}
		} catch (Exception $e) {
			$this->logger->error('{error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Check if the person sending a command has the right to execute it
	 *
	 * @param CmdContext     $context        The full command context
	 * @param string         $cmd            The name of the command that was requested
	 * @param CommandHandler $commandHandler The command handler for this command
	 *
	 * @return bool `true` if allowed to execute, otherwise `false`
	 */
	public function checkAccessLevel(CmdContext $context, string $cmd, CommandHandler $commandHandler): bool {
		if ($this->accessManager->checkAccess($context->char->name, $commandHandler->access_level) === true) {
			return true;
		}
		if ($context->isDM()) {
			$r = new RoutableMessage("Player <highlight>{$context->char->name}<end> was denied access to command <highlight>{$cmd}<end>.");
			$r->appendPath(new Source(Source::SYSTEM, 'access-denied'));
			$this->messageHub->handle($r);
		}

		// if they've disabled feedback for guild or private channel, just return
		if (isset($context->mapping) && !$context->mapping->feedback) {
			return false;
		}

		$charAL = $this->accessManager->getAccessLevelForCharacter($context->char->name);
		if ($charAL === AccessLevel::All) {
			$context->reply($this->noMemberErrorMsg);
		} else {
			$context->reply($this->accessDeniedErrorMsg);
		}
		return false;
	}

	/**
	 * Find the first matching command handler from the given list,
	 * and execute it in the given command context
	 *
	 * @return ?string The name of the command handler that matched, or `null` if none matched
	 */
	public function executeCommandHandler(CommandHandler $commandHandler, CmdContext $context): ?string {
		$successfulHandler = null;

		foreach ($commandHandler->files as $handler) {
			[$name, $method] = explode('.', $handler);
			[$method, $line] = explode(':', $method);
			$instance = Registry::tryGetInstance($name);
			if ($instance === null) {
				$this->logger->error("Could not find instance for name '{instance}'", [
					'instance' => $name,
				]);
				continue;
			}
			$arr = $this->checkMatches($instance, $method, $context->message);
			if ($arr === false) {
				continue;
			}
			$context->args = is_bool($arr) ? [] : $arr;
			$refClass = new ReflectionClass($instance);
			$refMethod = $refClass->getMethod($method);
			$params = $refMethod->getParameters();

			/** @psalm-suppress TypeDoesNotContainNull */
			if (count($params) === 0
				|| !$params[0]->hasType()
				|| ($type = $params[0]->getType()) === null
				|| !($type instanceof ReflectionNamedType)
				|| ($type->getName() !== CmdContext::class)
			) {
				continue;
			}
			$args = [];
			try {
				for ($i = 1; $i < count($params); $i++) {
					$var = $params[$i]->getName();
					if (!$params[$i]->hasType() || !isset($context->args[$var]) || ($context->args[$var] === '' && $params[$i]->allowsNull())) {
						if (!$params[$i]->isVariadic()) {
							$args []= null;
						}
						continue;
					}
					$type = $params[$i]->getType();
					if (!$this->isValidParamType($type)) {
						$args []= null;
						continue;
					}

					/** @var ReflectionNamedType $type */
					if (is_array($context->args[$var]) && !$params[$i]->isVariadic()) {
						$context->args[$var] = $context->args[$var][0];
					}
					$className = $type->getName();
					switch ($className) {
						case 'int':
							foreach ((array)$context->args[$var] as $val) {
								$args []= (int)$val;
							}
							break;
						case 'bool':
							foreach ((array)$context->args[$var] as $val) {
								$args []= in_array(strtolower($val), ['yes', 'true', '1', 'on', 'enable', 'enabled'], true);
							}
							break;
						case 'float':
							foreach ((array)$context->args[$var] as $val) {
								$args []= (float)$val;
							}
							break;
						default:
							if (is_subclass_of($className, Base::class)) {
								foreach ((array)$context->args[$var] as $val) {
									/** @psalm-suppress UnsafeInstantiation */
									$args []= new $className($val);
								}
							} elseif (is_subclass_of($className, EnumParameterInterface::class)) {
								foreach ((array)$context->args[$var] as $val) {
									$args []= $className::fromParam($val);
								}
							} else {
								foreach ((array)$context->args[$var] as $val) {
									$args []= $val;
								}
							}
							break;
					}
				}
				$refAttr = $refMethod->getAttributes(NCA\HandlesCommand::class);
				$lock = null;
				if (count($refAttr)) {
					$attr = $refAttr[0]->newInstance();
					$mutexKey = $attr->mutex;
					if (isset($mutexKey)) {
						$lock = $this->mutex->acquire($mutexKey);
					}
				}
				try {
					$methodResult = $refMethod->invoke($instance, $context, ...$args);
				} finally {
					if (isset($lock)) {
						$lock->release();
					}
				}
			} catch (UserException $e) {
				$context->reply($e->getMessage());
				return $handler;
			}
			if ($methodResult !== false) {
				// we can stop looking, command was handled successfully
				return $handler;
			}
		}

		return $successfulHandler;
	}

	/** Get the command handler that is responsible for handling a command */
	public function getActiveCommandHandler(string $cmd, string $permissionSet, string $message): ?CommandHandler {
		// Check if there is an alias for this command that should take precedence
		$parts = explode(' ', $message);
		// Only consider aliases like "raid add" and not "raid"
		while (count($parts) > 1) {
			$command = strtolower(implode(' ', $parts));
			$handler = $this->commands[$permissionSet][$command] ?? null;
			if ($handler instanceof CommandHandler) {
				return clone $handler;
			}
			array_pop($parts);
		}
		$handler = $this->commands[$permissionSet][$cmd] ?? null;
		if (!isset($handler)) {
			return null;
		}
		$handler = clone $handler;
		// Check if a subcommands for this exists
		if (isset($this->subcommandManager->subcommands[$cmd])) {
			foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
				if (isset($row->permissions[$permissionSet])) {
					$handler->addFile(...explode(',', $row->file));
				}
			}
		}
		$handler->files = $this->sortCalls($handler->files);
		return $handler;
	}

	/**
	 * Sort the given calls by order of definition
	 *
	 * @param list<string> $calls A list of handlers
	 *
	 * @return list<string> The sorted list
	 */
	public function sortCalls(array $calls): array {
		if (count($calls) < 2) {
			return $calls;
		}
		$objs = array_map(CmdCallHandler::fromString(...), $calls);
		usort($objs, static function (CmdCallHandler $call1, CmdCallHandler $call2): int {
			return $call1->compare($call2);
		});
		$result = array_map(static fn (CmdCallHandler $call): string => (string)$call, $objs);
		return $result;
	}

	/** Check if a given command is active for the given permission set */
	public function isCommandActive(string $cmd, string $permissionSet): bool {
		$parts = explode(' ', $cmd, 2);
		if (count($parts) === 1) {
			return isset($this->commands[$permissionSet][$cmd]);
		}
		if (isset($this->subcommandManager->subcommands[$parts[0]])) {
			foreach ($this->subcommandManager->subcommands[$parts[0]] as $row) {
				if (!isset($row->permissions[$permissionSet])) {
					continue;
				}
				if ($row->cmd !== $cmd) {
					continue;
				}
				if ($row->permissions[$permissionSet]->enabled) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get the help text for a command
	 *
	 * @return string The help text or `"Unknown command '$cmd'"` if none found
	 */
	public function getHelpForCommand(string $cmd, CmdContext $context): string {
		$result = $this->get($cmd);
		if (!isset($result)) {
			return "Unknown command '{$cmd}'";
		}

		return $this->getCmdHelpFromCode($cmd, $context);
	}

	/**
	 * Get the help text for a command, purely from the code
	 *
	 * @return string The help text or `"No help for $cmd"` if none found
	 */
	public function getCmdHelpFromCode(string $cmd, CmdContext $context): string {
		$cmds = $this->db->table(CmdCfg::getTable())
			->where('dependson', $cmd)
			->orWhere('cmd', $cmd)
			->asObj(CmdCfg::class)
			->pluckStrings('file')
			->join(',');
		if ($cmds === '' ||  !isset($context->permissionSet)) {
			return "No help for {$cmd}.";
		}
		$parts = [];
		$prologues = [];
		$epilogues = [];

		/** @var Collection<int,ReflectionMethod> */
		$methods = new Collection();
		foreach (explode(',', $cmds) as $handler) {
			if (null !== ($method = $this->getRefMethodForHandler(CmdCallHandler::fromString($handler)))) {
				$methods->push($method);
			}
		}

		/** @var Collection<int, ReflectionMethod> */
		$ms = new Collection();
		foreach ($methods as $m) {
			foreach ($m->getAttributes(NCA\Help\Group::class) as $attr) {
				$attrObj = $attr->newInstance();
				$ms->push(...$this->findGroupMembers($attrObj->group)->toArray());
			}
		}
		$methods = $methods->merge($ms)->unique();
		$methods = $methods->filter(function (ReflectionMethod $m) use ($context): bool {
			return $this->canViewHelp($context, $m);
		});
		$grouped = $this->groupRefMethods($methods->filter());
		$groupedByCmd = $this->groupBySubcmd($grouped);
		$showRights = $this->helpController->helpShowAL
			&& $this->accessManager->checkSingleAccess($context->char->name, AccessLevel::Mod);

		foreach ($groupedByCmd as $cmdName => $refGroups) {
			/** @var Collection<int,list<ReflectionMethod>> $refGroups */
			$header = "<header2>'{$cmdName}' command".
				((count($refGroups) > 1 || count($refGroups[0]) > 1) ? 's' : '');
			if ($showRights) {
				$cmdCfg = $this->get((string)$cmdName); // @phpstan-ignore-line
				if (isset($cmdCfg, $cmdCfg->permissions[$context->permissionSet])) {
					$al = $cmdCfg->permissions[$context->permissionSet]->access_level;
					$header .= " ({$al->displayName()})";
				}
			}
			$header .= '<end>';
			$parts []= $header;
			foreach ($refGroups as $refMethods) {
				$parts []= $this->getHelpText($refMethods);
				if (count($prologue = $refMethods[0]->getAttributes(NCA\Help\Prologue::class)) > 0) {
					$prologue = $prologue[0]->newInstance();
					$prologues []= $prologue->text;
				}
				if (count($epilogue = $refMethods[0]->getAttributes(NCA\Help\Epilogue::class)) > 0) {
					$epilogue = $epilogue[0]->newInstance();
					$epilogues []= $epilogue->text;
				}
			}
		}
		if (!count($parts)) {
			return "No help for {$cmd}.";
		}
		$blob = implode("\n\n", $parts);
		if (count($prologues)) {
			$blob = implode("\n\n", $prologues) . "\n\n{$blob}";
		}
		if (count($epilogues)) {
			$blob .= "\n\n" . implode("\n\n", $epilogues);
		}
		return Text::makeBlob("Help ({$cmd})", $blob . $this->getSyntaxExplanation($context));
	}

	/**
	 * Get a footer to display at the bottom of every help page that links to an
	 * explanation, how to read the syntax definition.
	 */
	public function getSyntaxExplanation(CmdContext $context, bool $ignorePrefs=false): string {
		$showSyntax = $this->preferences->get($context->char->name, HelpController::LEGEND_PREF) ?? '1';
		if ($showSyntax === '0' && !$ignorePrefs) {
			return '';
		}
		return "\n\n<i>See " . Text::makeChatcmd('<symbol>help syntax', '/tell <myname> help syntax').
			' for an explanation of the command syntax</i>';
	}

	/**
	 * Get the help text for a single parameter to a command
	 *
	 * @param ReflectionParameter $param      The parameter of the command function
	 * @param int                 $paramCount The absolute position of this parameter in the parameter list
	 *
	 * @return ?string `null` if we're unable to infer a text, otherwise the text
	 */
	public function getParamText(ReflectionParameter $param, int $paramCount): ?string {
		if (!$param->hasType()) {
			return null;
		}
		$type = $param->getType();
		if (!$this->isValidParamType($type)) {
			return null;
		}

		/** @var ReflectionNamedType $type */
		$niceName = Safe::pregReplaceCallback(
			'/([A-Z]+)/',
			static function (array $matches): string {
				return ' ' . strtolower($matches[1]);
			},
			$param->getName(),
		);
		$niceName = "&lt;{$niceName}&gt;";
		$class = $type->getName();
		if ($type->isBuiltin()) {
			$attrs = $param->getAttributes(ParamAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			if (count($attrs) > 0) {
				return implode(
					'|',
					array_map(static function (ReflectionAttribute $attr) use ($param): string {
						/** @var ParamAttribute */
						$attrObj = $attr->newInstance();
						return $attrObj->renderParameter($param);
					}, $attrs)
				);
			}
			switch ($type->getName()) {
				case 'bool':
					if ($param->getPosition() !== $paramCount - 1) {
						return 'enable|disable';
					}
					return 'yes|no';
				default:
					return $niceName;
			}
		} elseif (is_subclass_of($class, Base::class)) {
			$example = $class::getExample();
			if (isset($example)) {
				$niceName = $example;
			}
		} elseif (is_subclass_of($class, EnumExampleInterface::class)) {
			$niceName = $class::getExample();
		}
		return $niceName;
	}

	/**
	 * Check if a received message matches the stored Regexp handler of a method
	 *
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

		if (count($regexes) === 0) {
			return true;
		}
		foreach ($regexes as $regex) {
			if (count($arr = Safe::pregMatch($regex->match, $message))) {
				if (isset($regex->variadicMatch) && strlen($regex->variadicMatch)) {
					if (count($arr2 = Safe::pregMatchAll($regex->variadicMatch, $message)) > 0) {
						$arr = $arr2;
					}
				}
				return $arr;
			}
		}
		return false;
	}

	/**
	 * Get all stored regular expression Matches for a function
	 *
	 * @return list<CommandRegexp>
	 */
	public function retrieveRegexes(ReflectionMethod $reflectedMethod): array {
		if (count($reflectedMethod->getAttributes(NCA\HandlesCommand::class))) {
			return $this->getRegexpFromCharClass($reflectedMethod);
		}
		return [];
	}

	/**
	 * Get the regular expressions that a command must match to fulfill the given
	 * `ReflectionMethod`'s parameters.
	 *
	 * @return list<CommandRegexp>
	 */
	public function getRegexpFromCharClass(ReflectionMethod $method): array {
		$params = $method->getParameters();
		if (count($params) === 0
			|| !$params[0]->hasType()) {
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
				$cmdObj = $command->newInstance();
				$commands []= explode(' ', $cmdObj->command)[0];
			}
			if (count($commands) === 1) {
				$regexp = $commands;
			} else {
				$regexp []= '(?:' . implode('|', $commands) . ')';
			}
		}
		$comment = $method->getDocComment();
		if ($comment === false) {
			$comment = '';
		}
		$variadic = null;
		for ($i = 1; $i < count($params); $i++) {
			$regex = $this->getParamRegexp($params[$i]);
			if ($regex === null) {
				return [];
			}
			if (isset($regex->variadicMatch)) {
				$variadic = ['(?:^', ...$regexp, '|\\G)'];
				$variadic []= $regex->variadicMatch;
			}
			$regexp []= $regex->match;
		}
		$regexp = new CommandRegexp(chr(1) . '^' . implode('', $regexp) . '$' . chr(1) . 'is');
		if (isset($variadic)) {
			$regexp->variadicMatch = chr(1) . implode('', $variadic) . chr(1) . 'is';
		}
		$result = [$regexp];
		return $result;
	}

	/** {@inheritDoc} */
	public function getChannelName(): string {
		return Source::SYSTEM . '(access-denied)';
	}

	/** Get a permission set by its name */
	public function getPermissionSet(string $name): ?CmdPermissionSet {
		$permSet = $this->db->table(CmdPermissionSet::getTable())
			->where('name', $name)
			->firstObj(CmdPermissionSet::class);
		return $permSet;
	}

	/** Get a permission set and all its mappings by the permission set's name */
	public function getExtPermissionSet(string $name): ?ExtCmdPermissionSet {
		$permSet = $this->db->table(CmdPermissionSet::getTable())
			->where('name', $name)
			->firstObj(ExtCmdPermissionSet::class);
		if (isset($permSet)) {
			$permSet->mappings = $this->getPermSetMappings()
				->where('permission_set', $name)
				->values()
				->toList();
		}
		return $permSet;
	}

	/** Get the default permissions for a given command */
	public function getDefaultPermissions(string $cmd): ?CmdPermission {
		return $this->cmdDefaultPermissions[$cmd] ?? null;
	}

	/**
	 * Create a new set of permissions based on the default permissions of the bot
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 */
	public function createPermissionSet(string $name, string $letter): void {
		$allCmds = $this->getAll(true);
		$perms = [];
		foreach ($allCmds as $cmd) {
			$cmdPerms = ($cmd->cmdevent === 'cmd')
				? $this->getDefaultPermissions($cmd->cmd)
				: $this->subcommandManager->getDefaultPermissions($cmd->cmd);
			if (!isset($cmdPerms)) {
				throw new Exception("There are no default permissions registered for {$cmd->cmd}.");
			}
			$cmdPerms->permission_set = $name;
			$perms []= $cmdPerms;
		}
		$this->insertPermissionSet($name, $letter, ...$perms);
	}

	/**
	 * Change a permission set to the data given
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 */
	public function changePermissionSet(string $name, CmdPermissionSet $data): void {
		$old = $this->getPermissionSet($name);
		if (!isset($old)) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> does not exist.");
		}
		if ($data->name !== $old->name) {
			$newNameExists = $this->db->table(CmdPermissionSet::getTable())
				->where('name', $data->name)->exists();
			if ($newNameExists) {
				throw new InvalidArgumentException(
					"A permission set <highlight>{$data->name}<end> already exists."
				);
			}
		}
		if ($data->letter !== $old->letter) {
			$newLetterExists = $this->db->table(CmdPermissionSet::getTable())
				->where('letter', $data->letter)->exists();
			if ($newLetterExists) {
				throw new InvalidArgumentException(
					"A permission set with the letter <highlight>{$data->letter}<end> already exists."
				);
			}
		}
		$this->db->awaitBeginTransaction();
		try {
			$this->db->table(CmdPermissionSet::getTable())
				->where('name', $name)
				->update([
					'name' => $data->name,
					'letter' => $data->letter,
				]);
			if ($data->name !== $old->name) {
				$this->db->table(CmdPermSetMapping::getTable())
					->where('permission_set', $name)
					->update(['permission_set' => $data->name]);
				$this->db->table(CmdPermission::getTable())
					->where('permission_set', $name)
					->update(['permission_set' => $data->name]);
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
	 */
	public function clonePermissionSet(string $oldName, string $name, string $letter): void {
		$perms = $this->db->table(CmdPermission::getTable())
			->where('permission_set', $oldName)
			->asObjArr(CmdPermission::class);
		$this->insertPermissionSet($name, $letter, ...$perms);
	}

	/**
	 * Delete a permission set by its name
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 * @throws SQLException             on database errors
	 */
	public function deletePermissionSet(string $name): void {
		$name = strtolower($name);
		if (!$this->db->table(CmdPermissionSet::getTable())->where('name', $name)->exists()) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> does not exist.");
		}
		$usedBy = collect($this->getSourcesForPermsetName($name));
		if ($usedBy->count() > 0) {
			throw new InvalidArgumentException(
				"The permission set <highlight>{$name}<end> is still assigned to <highlight>".
				$usedBy->join('<end>, <highlight>', '<end> and <highlight>').
				'<end>.'
			);
		}
		$this->db->awaitBeginTransaction();
		try {
			$this->db->table(CmdPermission::getTable())
				->where('permission_set', $name)
				->delete();
			$this->db->table(CmdPermissionSet::getTable())
				->where('name', $name)
				->delete();
		} catch (Exception $e) {
			$this->db->rollback();
			throw new SQLException('There was an unknown error deleting that permission set.', 0, $e);
		}
		$this->db->commit();
		unset($this->commands[$name]);
		$this->subcommandManager->loadSubcommands();
	}

	/**
	 * Delete a permission set mapping
	 *
	 * @throws InvalidArgumentException when one of the parameters is invalid
	 */
	public function deletePermissionSetMapping(string $source): bool {
		$numMappings = $this->getPermSetMappings()->count();
		if ($numMappings < 2) {
			throw new Exception('You cannot delete the last permission mapping.');
		}
		$source = strtolower($source);
		if ($this->getPermSetMappings()->where('source', $source)->isEmpty()) {
			return false;
		}
		$numDeleted = $this->db->table(CmdPermSetMapping::getTable())
			->where('source', $source)
			->delete();
		if ($numDeleted === 0) {
			return false;
		}
		$this->loadPermsetMappings();
		return true;
	}

	/**
	 * Try to determine to which permission set a routing source maps
	 *
	 * @param string $source Name of the source, e.g. aopriv(Tester)
	 *
	 * @return null|CmdPermSetMapping The mapping of the permission set, or null if no execution intended
	 */
	public function getPermsetMapForSource(string $source): ?CmdPermSetMapping {
		foreach ($this->permSetMappings as $map) {
			if (fnmatch($map->source, $source, \FNM_CASEFOLD)) {
				return $map;
			}
		}
		return null;
	}

	/**
	 * Get the names of all sources using the permission set $name
	 *
	 * @param string $name Name of the permission set
	 *
	 * @return Generator<array-key,string> A list of all sources mapping to this
	 */
	public function getSourcesForPermsetName(string $name): Generator {
		$name = strtolower($name);
		foreach ($this->permSetMappings as $map) {
			if ($map->permission_set === $name) {
				yield $map->source;
			}
		}
	}

	/** Check the message in $context for a valid command and execute it in the proper channel */
	public function checkAndHandleCmd(CmdContext $context): bool {
		if (!isset($context->source)) {
			return false;
		}
		$this->logger->info('Received msg from {source}', [
			'source' => $context->source,
		]);
		$cmdMap = $this->getPermsetMapForSource($context->source);
		if (!isset($cmdMap)) {
			return false;
		}
		$this->logger->info('Using permission set {permission_set}', [
			'permission_set' => $cmdMap->permission_set,
			'map' => $cmdMap,
		]);
		if (strncmp($context->message, $cmdMap->symbol, strlen($cmdMap->symbol)) === 0) {
			$context->message = substr($context->message, strlen($cmdMap->symbol));
		} elseif (!$cmdMap->symbol_optional) {
			return false;
		}

		$context->permissionSet = $cmdMap->permission_set;
		$context->mapping = $cmdMap;
		if (!isset($context->char->id)) {
			$this->processCmd($context);
			return true;
		}
		if ($this->banController->isOnBanlist($context->char->id)) {
			return false;
		}
		$this->processCmd($context);
		return true;
	}

	/**
	 * Get the help text for a list of `ReflectionMethod`s,
	 * assuming they all have the same help text, and are just
	 * grouped, because of different ways to call the command.
	 *
	 * @param iterable<array-key,ReflectionMethod> $ms A list of `ReflectionMethod`s
	 *
	 * @throws Exception on wrong function signature
	 */
	private function getHelpText(iterable $ms): string {
		$first = null;
		foreach ($ms as $m) {
			$first ??= $m;
			$params = $m->getParameters();
			if (count($params) === 0
				|| !$params[0]->hasType()) {
				throw new Exception('Wrong command function signature');
			}
			$type = $params[0]->getType();
			if (!($type instanceof ReflectionNamedType)
				|| ($type->getName() !== CmdContext::class)) {
				throw new Exception('Wrong command function signature');
			}
			$cmds = $m->getAttributes(NCA\HandlesCommand::class);
			if (count($cmds) === 0) {
				throw new Exception('Wrong command function signature');
			}
		}
		$lines = [];
		$extra = [];
		$comment = $first?->getDocComment() ?? false;
		if ($comment !== false) {
			$cleanComment = $this->cleanComment($comment);
			$lines []= trim($cleanComment->headline);
			if (isset($cleanComment->description)) {
				$extra []= '<i>' . trim($cleanComment->description) . '</i>';
			}
		}
		$j = -1;
		foreach ($ms as $m) {
			$j++;
			$params = $m->getParameters();
			$commandAttrs = $m->getAttributes(NCA\HandlesCommand::class);
			for ($k = 0; $k < count($commandAttrs); $k++) {
				$commandObj = $commandAttrs[$k]->newInstance();
				$commandName = explode(' ', $commandObj->command)[0];
				$paramText = ["<symbol>{$commandName}"];
				for ($i = 1; $i < count($params); $i++) {
					$niceParam = $this->getParamText($params[$i], count($params));
					if (!isset($niceParam)) {
						throw new Exception('Wrong command function signature');
					}
					if ($params[$i]->allowsNull()) {
						$niceParam = "[{$niceParam}]";
					}
					if ($params[$i]->isVariadic()) {
						$parMask = str_replace('&gt;', '%d&gt;', Safe::pregReplace("/s\b/", '', Safe::pregReplace("/ies\b/", 'y', $niceParam)));
						$ones = array_fill(0, substr_count($parMask, '%d'), 1);
						$twos = array_fill(0, substr_count($parMask, '%d'), 2);
						$niceParam = sprintf($parMask, ...$ones) . ' ' . sprintf($parMask, ...$twos) . ' ...';
					}
					if (count($params[$i]->getAttributes(NCA\Parameter\NoSpace::class))) {
						$niceParam = "\x08{$niceParam}";
					}
					$paramText []= $niceParam;
				}
				if ($j > 0 && $k === 0) {
					$lines []= 'or';
				}
				$lines []= '<tab><highlight>' . str_replace(" \x08", '', implode(' ', $paramText)) . '<end>';
			}
			$examples = $m->getAttributes(NCA\Help\Example::class);
			foreach ($examples as $exAttr) {
				$example = $exAttr->newInstance();
				$lines []= "<tab>-&gt; <highlight>{$example->command}<end>".
					(isset($example->description) ? " - {$example->description}" : '');
			}
		}
		if (count($extra) > 0) {
			$lines = array_merge($lines, $extra);
		}
		return implode("\n", $lines);
	}

	/**
	 * Group a collection of `ReflectionMethod`s by the doc block comment
	 *
	 * @param Collection<int,ReflectionMethod> $methods
	 *
	 * @return Collection<int,list<ReflectionMethod>>
	 */
	private function groupRefMethods(Collection $methods): Collection {
		/** @var array<string,list<ReflectionMethod>> */
		$lookup = [];
		$empty = [];
		foreach ($methods as $m) {
			$comment = $m->getDocComment();
			if ($comment === false) {
				$empty []= [$m];
				continue;
			}
			$headline = $this->cleanComment($comment)->headline;
			$lookup[$headline] ??= [];
			$lookup[$headline] []= $m;
		}

		/** @var Collection<int,list<ReflectionMethod>> */
		$result = collect(array_merge(array_values($lookup), $empty));
		return $result;
	}

	/** Activate a given command config in all its permission sets */
	private function activateCmdCfg(CmdCfg $command): void {
		foreach ($command->permissions as $permSet => $permission) {
			if (!$permission->enabled) {
				continue;
			}
			$this->activate($permission->permission_set, $command->file, $command->cmd, $permission->access_level);
		}
	}

	/**
	 * Check if a Command parameter has a valid type
	 *
	 * @psalm-assert-if-true ReflectionNamedType $type
	 */
	private function isValidParamType(ReflectionType $type): bool {
		if (!($type instanceof ReflectionNamedType)) {
			return false;
		}
		if ($type->isBuiltin()) {
			return true;
		}

		if (is_subclass_of($type->getName(), Base::class)) {
			return true;
		}
		return is_subclass_of($type->getName(), EnumParameterInterface::class);
	}

	/** Get the `ReflectionMethod` for a command call handler */
	private function getRefMethodForHandler(CmdCallHandler $handler): ?ReflectionMethod {
		$instance = Registry::tryGetInstance($handler->className);
		if ($instance === null) {
			$this->logger->error("Could not find instance for name '{instance}'", [
				'instance' => $handler->className,
			]);
			return null;
		}
		$refClass = new ReflectionClass($instance);
		try {
			$refMethod = $refClass->getMethod($handler->method);
		} catch (ReflectionException) {
			$this->logger->error('Could not find method {class}::{method}()', [
				'class' => $handler->className,
				'method' => $handler->method,
			]);
			return null;
		}
		return $refMethod;
	}

	/** Clean doc block comments so they can be shown to a user */
	private function cleanComment(string $comment): Comment {
		$comment = trim(Safe::pregReplace("|^/\*\*(.*)\*/|s", '$1', $comment));
		$comment = Safe::pregReplace("/^[ \t]*\*[ \t]*/m", '', $comment);
		$comment = trim(Safe::pregReplace('/^@.*/m', '', $comment));

		$result = Safe::pregSplit("/\r?\n\r?\n/", $comment, 2);
		return new Comment(
			headline: trim($result[0]),
			description: isset($result[1]) ? trim($result[1]) : null,
		);
	}

	/**
	 * Find all methods that have the same NCA\\Help\\Group set
	 *
	 * @return Collection<int,ReflectionMethod>
	 */
	private function findGroupMembers(string $groupName): Collection {
		$objs = Registry::getAllInstances();

		/** @var Collection<int,ReflectionMethod> */
		$ms = new Collection();
		foreach ($objs as $obj) {
			$refObj = new ReflectionClass($obj);
			foreach ($refObj->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
				foreach ($m->getAttributes(NCA\Help\Group::class) as $attr) {
					$attrObj = $attr->newInstance();
					if ($attrObj->group === $groupName) {
						$ms->push($m);
					}
				}
			}
		}
		return $ms;
	}

	/**
	 * Is the character in the given command context allowed to see
	 * the `ReflectionMethod $method`'s help?
	 */
	private function canViewHelp(CmdContext $context, ReflectionMethod $method): bool {
		if (count($method->getAttributes(NCA\Help\Hide::class)) > 0) {
			return false;
		}
		$cmdAttrs = $method->getAttributes(NCA\HandlesCommand::class);
		foreach ($cmdAttrs as $cmdAttr) {
			$handlesCommand = $cmdAttr->newInstance();
			$cmd = explode(' ', $handlesCommand->command)[0];
			if (isset($this->subcommandManager->subcommands[$cmd])) {
				foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
					if (!isset($row->permissions[$context->permissionSet])
						|| ($row->cmd !== $handlesCommand->command)
						|| (!$row->permissions[$context->permissionSet]->enabled)
					) {
						continue;
					}
					$handler = new CommandHandler($row->permissions[$context->permissionSet]->access_level, ...explode(',', $row->file));
				}
			}
			if (!isset($handler)) {
				$handler = $this->commands[$context->permissionSet][$cmd] ?? null;
			}
			if (!isset($handler)) {
				continue;
			}
			if ($this->accessManager->checkAccess($context->char->name, $handler->access_level) === true) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Group a list of `ReflectionMethod`s for commands by their subcommand
	 *
	 * @param Collection<int,list<ReflectionMethod>> $list
	 *
	 * @return Collection<string,Collection<int, list<ReflectionMethod>>>
	 *
	 * @psalm-suppress InvalidReturnType
	 */
	private function groupBySubcmd(Collection $list): Collection {
		/**
		 * @param list<ReflectionMethod> $refMethods1
		 * @param list<ReflectionMethod> $refMethods2
		 */
		$sList = $list->sort(static function (array $refMethods1, array $refMethods2): int {
			$n1 = $refMethods1[0]->getDeclaringClass()->getShortName();
			$n2 = $refMethods2[0]->getDeclaringClass()->getShortName();
			$firstCmp = strcmp($n1, $n2);
			return ($firstCmp !== 0)
				? $firstCmp
				: $refMethods1[0]->getStartLine() <=> $refMethods2[0]->getStartLine();
		});

		/**
		 * @param list<ReflectionMethod> $refMethods
		 */
		$grouped = $sList->groupBy(static function (array $refMethods): string {
			if (!count($refMethods)) {
				return '';
			}
			$attrs = $refMethods[0]->getAttributes(NCA\HandlesCommand::class);
			if (!count($attrs)) {
				return '';
			}

			$handlesCmd = $attrs[0]->newInstance();
			return $handlesCmd->command;
		});

		/** @psalm-suppress InvalidReturnStatement */
		return $grouped;
	}

	/**
	 * Get the regular expression needed to match a single parameter to a command
	 *
	 * @return ?CommandRegexp `null` if not a valid parameter, otherwise a regexp object
	 */
	private function getParamRegexp(ReflectionParameter $param): ?CommandRegexp {
		if (!$param->hasType()) {
			return null;
		}
		$type = $param->getType();
		if (!$this->isValidParamType($type)) {
			return null;
		}

		$varName = $param->getName();
		if ($type->isBuiltin()) {
			$mask = null;
			$attrs = $param->getAttributes(ParamAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
			if (count($attrs) > 0) {
				$mask = implode(
					'|',
					array_map(static function (ReflectionAttribute $attr): string {
						/** @var ParamAttribute */
						$attrObj = $attr->newInstance();
						return $attrObj->getRegexp();
					}, $attrs)
				);
			}
			switch ($type->getName()) {
				case 'string':
					$mask ??= '.+';
					$new = "(?<{$varName}>{$mask})";
					break;
				case 'int':
					$mask ??= '\d+';
					$new = "(?<{$varName}>{$mask})";
					break;
				case 'bool':
					$new = "(?<{$varName}>true|false|yes|no|on|off|enabled?|disabled?)";
					break;
				case 'float':
					$mask ??= '\d*\.?\d+';
					$new  = "(?<{$varName}>{$mask})";
					break;
			}
		} elseif (is_subclass_of($type->getName(), EnumParameterInterface::class)) {
			$class = $type->getName();
			$new = "(?<{$varName}>" . $class::getParamRegexp() . ')';
		} else {
			$c1 = [$type->getName(), 'getPreRegExp'];
			$c2 = [$type->getName(), 'getRegexp'];
			if (is_callable($c1) && is_callable($c2)) {
				$new = '(?:' . $c1() . "(?<{$varName}>" . $c2() . '))';
			}
		}
		if (!isset($new)) {
			return null;
		}
		if (count($param->getAttributes(NCA\Parameter\SpaceOptional::class))) {
			$regexp = new CommandRegexp("\\s*{$new}");
		} elseif (count($param->getAttributes(NCA\Parameter\NoSpace::class))) {
			$regexp = new CommandRegexp($new);
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

	/** Insert new permission set(s) into the  database */
	private function insertPermissionSet(string $name, string $letter, CmdPermission ...$perms): void {
		$letter = strtoupper($letter);
		$name = strtolower($name);
		if (strlen($letter) !== 1) {
			throw new InvalidArgumentException('The letter of a permission set must be exactly 1 character long.');
		}
		if ($this->db->table(CmdPermissionSet::getTable())->where('name', $name)->exists()) {
			throw new InvalidArgumentException("The permission set <highlight>{$name}<end> already exists.");
		}
		if ($this->db->table(CmdPermissionSet::getTable())->where('letter', $letter)->exists()) {
			throw new InvalidArgumentException("A permission set with the letter <highlight>{$letter}<end> already exists.");
		}
		$inserts = [];
		foreach ($perms as $perm) {
			$perm->id = Uuid::uuid7();
			$perm->permission_set = $name;
			$inserts []= (array)$perm;
		}
		$inTransaction = $this->db->inTransaction();
		if (!$inTransaction) {
			$this->db->beginTransaction();
		}
		try {
			$this->db->insert(new CmdPermissionSet(
				name: $name,
				letter: $letter,
			));
			$this->db->table(CmdPermission::getTable())
				->chunkInsert($inserts);
		} catch (Exception $e) {
			if (!$inTransaction) {
				$this->db->rollback();
			}
			throw new Exception('There was an unknown error saving the new permission set.', 0, $e);
		}
		if (!$inTransaction) {
			$this->db->commit();
		}
		$this->loadCommands();
		$this->subcommandManager->loadSubcommands();
	}
}
