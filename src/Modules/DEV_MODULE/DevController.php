<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use ReflectionMethod;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandAlias,
	CommandHandler,
	CommandManager,
	DB,
	Registry,
	SubcommandManager,
	Text,
	Util,
};
use Nadybot\Core\Attributes as NCA;
use ReflectionException;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "showcmdregex",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "intransaction",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "rollbacktransaction",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "stacktrace",
		accessLevel: "admin",
		description: "Test the bot commands",
		help: "test.txt"
	),
	NCA\DefineCommand(
		command: "cmdhandlers",
		accessLevel: "admin",
		description: "Show command handlers for a command",
		help: "cmdhandlers.txt"
	),
	NCA\DefineCommand(
		command: "createblob",
		accessLevel: "admin",
		description: "Creates a blob of random characters",
		help: "createblob.txt"
	),
	NCA\DefineCommand(
		command: "makeitem",
		accessLevel: "admin",
		description: "Creates an item link",
		help: "makeitem.txt"
	)
]
class DevController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

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

	#[NCA\HandlesCommand("showcmdregex")]
	public function showcmdregexCommand(CmdContext $context, ?string $cmd): void {
		// get all command handlers
		$handlers = $this->getAllCommandHandlers($cmd, $context->channel);

		// filter command handlers by access level
		$accessManager = $this->accessManager;
		$handlers = array_filter($handlers, function (CommandHandler $handler) use ($context, $accessManager) {
			return $accessManager->checkAccess($context->char->name, $handler->admin);
		});

		// get calls for handlers
		/** @var string[] */
		$calls = array_reduce(
			$handlers,
			function (array $handlers, CommandHandler $handler) {
				return array_merge($handlers, explode(',', $handler->file));
			},
			[]
		);

		// get regexes for calls
		$regexes = [];
		foreach ($calls as $call) {
			[$name, $method] = explode(".", $call);
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
				$command = $commands[0]->newInstance()->value;
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
			$msg = $this->text->makeBlob("Regexes for $cmd ($count)", $blob);
		} else {
			$msg = $this->text->makeBlob("Regexes for commands ($count)", $blob);
		}
		$context->reply($msg);
	}

	/**
	 * @return CommandHandler[]
	 */
	public function getAllCommandHandlers(?string $command, string $channel): array {
		$handlers = [];
		if (!isset($command)) {
			$cmds = array_keys($this->commandManager->commands[$channel]);
		} else {
			$cmds = (array)$command;
		}
		foreach ($cmds as $cmd) {
			if (isset($this->commandManager->commands[$channel][$cmd])) {
				$handlers []= $this->commandManager->commands[$channel][$cmd];
			}
			if (isset($this->subcommandManager->subcommands[$cmd])) {
				foreach ($this->subcommandManager->subcommands[$cmd] as $handler) {
					if ($handler->type == $channel) {
						$handlers []= new CommandHandler($handler->file, $handler->admin);
					}
				}
			}
		}
		return $handlers;
	}

	#[NCA\HandlesCommand("intransaction")]
	public function inTransactionCommand(CmdContext $context): void {
		if ($this->db->inTransaction()) {
			$msg = "There is an active transaction.";
		} else {
			$msg = "There is no active transaction.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("rollbacktransaction")]
	public function rollbackTransactionCommand(CmdContext $context): void {
		$this->db->rollback();

		$msg = "The active transaction has been rolled back.";
		$context->reply($msg);
	}

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
		$msg = $this->text->makeBlob("Current Stacktrace ($count)", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("cmdhandlers")]
	public function cmdhandlersCommand(CmdContext $context, string $command): void {
		$cmdArray = explode(" ", $command, 2);
		$cmd = $cmdArray[0];

		$blob = '';

		// command
		foreach ($this->commandManager->commands as $channelName => $channel) {
			if (isset($channel[$cmd])) {
				$blob .= "<header2>$channelName ($cmd)<end>\n";
				$blob .= $channel[$cmd]->file . "\n\n";
			}
		}

		// subcommand
		foreach ($this->subcommandManager->subcommands[$cmd] as $row) {
			$blob .= "<header2>$row->type ($row->cmd)<end>\n";
			$blob .= $row->file . "\n\n";
		}

		$msg = $this->text->makeBlob("Command Handlers for '$cmd'", $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("makeitem")]
	public function makeItemCommand(CmdContext $context, int $lowId, int $highId, int $ql, string $name): void {
		$context->reply($this->text->makeItem($lowId, $highId, $ql, $name));
	}

	#[NCA\HandlesCommand("createblob")]
	public function createBlobCommand(CmdContext $context, int $length, ?int $numBlobs): void {
		$numBlobs ??= 1;

		for ($i = 0; $i < $numBlobs; $i++) {
			$blob = $this->randString($length);
			$msg = $this->text->makeBlob("Blob $i", $blob);
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
