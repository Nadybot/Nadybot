<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandHandler,
	CommandManager,
	DB,
	ModuleInstance,
	Registry,
	SubcommandManager,
	Text,
	Util,
};
use ReflectionException;
use ReflectionMethod;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "showcmdregex",
		accessLevel: "admin",
		description: "View regex masks used to match commands",
	),
	NCA\DefineCommand(
		command: "intransaction",
		accessLevel: "admin",
		description: "Check if a DB transaction is open",
	),
	NCA\DefineCommand(
		command: "rollbacktransaction",
		accessLevel: "admin",
		description: "Rollback an open DB transaction",
	),
	NCA\DefineCommand(
		command: "stacktrace",
		accessLevel: "admin",
		description: "Show a stacktrace",
	),
	NCA\DefineCommand(
		command: "cmdhandlers",
		accessLevel: "admin",
		description: "Show command handlers for a command",
	),
	NCA\DefineCommand(
		command: "createblob",
		accessLevel: "admin",
		description: "Creates a blob of random characters",
	),
	NCA\DefineCommand(
		command: "makeitem",
		accessLevel: "guest",
		description: "Creates an item link",
	)
]
class DevController extends ModuleInstance {
	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public SubcommandManager $subcommandManager;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "querysql select", "select");
	}

	/**
	 * Get a list of regular expressions for either all commands
	 * or only the command and sub-commands of &lt;cmd&gt;
	 */
	#[NCA\HandlesCommand("showcmdregex")]
	public function showcmdregexCommand(CmdContext $context, ?string $cmd): void {
		if (!isset($context->permissionSet)) {
			return;
		}
		if (isset($cmd) && ($alias = $this->commandAlias->get($cmd)) !== null) {
			$cmd = $alias->cmd;
		}
		// get all command handlers
		$handlers = $this->getAllCommandHandlers($cmd, $context->permissionSet);

		// filter command handlers by access level
		$accessManager = $this->accessManager;
		$handlers = array_filter($handlers, function (CommandHandler $handler) use ($context, $accessManager): bool {
			return $accessManager->checkAccess($context->char->name, $handler->access_level);
		});

		// get calls for handlers
		/** @var string[] */
		$calls = array_reduce(
			$handlers,
			function (array $handlers, CommandHandler $handler): array {
				return array_merge($handlers, $handler->files);
			},
			[]
		);

		$this->commandManager->sortCalls($calls);

		// get regexes for calls
		$regexes = [];
		foreach ($calls as $call) {
			[$name, $method] = explode(".", $call);
			[$method, $line] = explode(":", $method);
			$instance = Registry::getInstance($name);
			if (!isset($instance)) {
				continue;
			}
			try {
				$reflectedMethod = new ReflectionMethod($instance, $method);
				$commands = $reflectedMethod->getAttributes(NCA\HandlesCommand::class);
				if (empty($commands)) {
					continue;
				}

				/** @var NCA\HandlesCommand */
				$commandObj = $commands[0]->newInstance();
				$command = $commandObj->command;
				$command = explode(" ", $command)[0];
				$regexes[$command] ??= [];
				$regexes[$command] = array_merge($regexes[$command], $this->commandManager->retrieveRegexes($reflectedMethod));
			} catch (ReflectionException $e) {
				continue;
			}
		}
		ksort($regexes);

		$count = count($regexes);
		if ($count === 0) {
			$msg = "No regexes found for command <highlight>{$cmd}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = "";
		foreach ($regexes as $command => $list) {
			$blob .= "<header2>{$command}<end>";
			foreach ($list as $regex) {
				if (preg_match("/^(.)(.+?)\\1([a-z]*)$/", $regex->match, $matches)) {
					$regex->match = "(?{$matches[3]})  {$matches[2]}";
					$regex->match = preg_replace("/\(\?<.+?>/", "(", $regex->match);
				}
				$blob .= "\n<tab>" . htmlspecialchars($regex->match);
			}
			$blob .= "\n\n";
		}
		if (isset($cmd)) {
			$msg = $this->text->makeBlob("Regexes for {$cmd} ({$count})", $blob);
		} else {
			$msg = $this->text->makeBlob("Regexes for commands ({$count})", $blob);
		}
		$context->reply($msg);
	}

	/** @return CommandHandler[] */
	public function getAllCommandHandlers(?string $command, string $channel): array {
		$handlers = [];
		if (!isset($command)) {
			$cmds = array_keys($this->commandManager->commands[$channel]);
		} else {
			$cmds = (array)$command;
		}
		foreach ($cmds as $cmd) {
			if (isset($this->subcommandManager->subcommands[$cmd])) {
				foreach ($this->subcommandManager->subcommands[$cmd] as $handler) {
					if (isset($handler->permissions[$channel])) {
						$handlers []= new CommandHandler($handler->permissions[$channel]->access_level, ...explode(",", $handler->file));
					}
				}
			}
			if (isset($this->commandManager->commands[$channel][$cmd])) {
				$handlers []= $this->commandManager->commands[$channel][$cmd];
			}
		}
		return $handlers;
	}

	/** Check if there is currently a open database transaction */
	#[NCA\HandlesCommand("intransaction")]
	public function inTransactionCommand(CmdContext $context): void {
		if ($this->db->inTransaction()) {
			$msg = "There is an active transaction.";
		} else {
			$msg = "There is no active transaction.";
		}
		$context->reply($msg);
	}

	/** Rollback the currently open database transaction (if any) */
	#[NCA\HandlesCommand("rollbacktransaction")]
	public function rollbackTransactionCommand(CmdContext $context): void {
		$this->db->rollback();

		$msg = "The active transaction has been rolled back.";
		$context->reply($msg);
	}

	/** Print a stacktrace */
	#[NCA\HandlesCommand("stacktrace")]
	public function stacktraceCommand(CmdContext $context): void {
		$stacktrace = trim($this->util->getStackTrace());
		$lines = explode("\n", $stacktrace);
		$count = count($lines);
		$blob = "<header2>Current call stack<end>";
		for ($i = 0; $i < $count; $i++) {
			$pos = $count-$i;
			$blob .= "\n<tab>" . $this->text->alignNumber($pos, 2, "highlight") . ". {$lines[$i]}";
		}
		$msg = $this->text->makeBlob("Current Stacktrace ({$count})", $blob);
		$context->reply($msg);
	}

	/** Print all command handlers for a command, grouped by permission set */
	#[NCA\HandlesCommand("cmdhandlers")]
	public function cmdhandlersCommand(CmdContext $context, string $command): void {
		$cmdArray = explode(" ", $command, 2);
		$cmd = $cmdArray[0];

		$blob = '';

		// command
		foreach ($this->commandManager->commands as $channelName => $channel) {
			if (isset($channel[$cmd])) {
				$blob .= "<header2>{$channelName} ({$cmd})<end>\n";
				$blob .= join(", ", $channel[$cmd]->files) . "\n\n";
			}
		}

		// subcommand
		foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
			foreach ($row->permissions as $permission) {
				$blob .= "<header2>{$permission->permission_set} ({$row->cmd})<end>\n";
				$blob .= $row->file . "\n\n";
			}
		}

		$msg = $this->text->makeBlob("Command Handlers for '{$cmd}'", $blob);

		$context->reply($msg);
	}

	/** Create a custom item link */
	#[NCA\HandlesCommand("makeitem")]
	public function makeItemCommand(CmdContext $context, int $lowId, int $highId, int $ql, string $name): void {
		$context->reply($this->text->makeItem($lowId, $highId, $ql, $name));
	}

	/** Create 1 or &lt;num blobs&gt; blobs of &lt;length&gt; characters */
	#[NCA\HandlesCommand("createblob")]
	public function createBlobCommand(CmdContext $context, int $length, ?int $numBlobs): void {
		$numBlobs ??= 1;

		for ($i = 0; $i < $numBlobs; $i++) {
			$blob = $this->randString($length);
			$msg = $this->text->makeBlob("Blob {$i}", $blob);
			$context->reply($msg);
		}
	}

	public function randString(int $length, string $charset='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 \n'): string {
		$str = '';
		$count = strlen($charset);
		while ($length--) {
			$str .= $charset[mt_rand(0, $count-1)];
		}
		return $str;
	}
}
