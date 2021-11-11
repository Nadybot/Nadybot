<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Addendum\ReflectionAnnotatedMethod;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandAlias,
	CommandHandler,
	CommandManager,
	CommandReply,
	DB,
	Registry,
	SubcommandManager,
	Text,
	Util,
};
use ReflectionException;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'showcmdregex',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'intransaction',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'rollbacktransaction',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'stacktrace',
 *		accessLevel = 'admin',
 *		description = "Test the bot commands",
 *		help        = 'test.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'cmdhandlers',
 *		accessLevel = 'admin',
 *		description = "Show command handlers for a command",
 *		help        = 'cmdhandlers.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'createblob',
 *		accessLevel = 'admin',
 *		description = "Creates a blob of random characters",
 *		help        = 'createblob.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'makeitem',
 *		accessLevel = 'admin',
 *		description = "Creates an item link",
 *		help        = 'makeitem.txt'
 *	)
 */
class DevController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public SubcommandManager $subcommandManager;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "querysql select", "select");
	}

	/**
	 * @HandlesCommand("showcmdregex")
	 */
	public function showcmdregexCommand(CmdContext $context, string $cmd): void {
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
			try {
				$reflectedMethod = new ReflectionAnnotatedMethod($instance, $method);
				$regexes = array_merge($regexes, $this->commandManager->retrieveRegexes($reflectedMethod));
			} catch (ReflectionException $e) {
				continue;
			}
		}

		$count = count($regexes);
		if ($count > 0) {
			$blob = "<header2>Regular expressions<end>\n";
			foreach ($regexes as $regex) {
				if (preg_match("/^(.)(.+?)\\1([a-z]*)$/", $regex, $matches)) {
					$regex = "(?{$matches[3]})  {$matches[2]}";
					$regex = preg_replace("/\(\?<.+?>/", "(", $regex);
				}
				$blob .= "<tab>{$regex}\n";
			}
			$msg = $this->text->makeBlob("Regexes for $cmd ($count)", $blob);
		} else {
			$msg = "No regexes found for command <highlight>$cmd<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * @return CommandHandler[]
	 */
	public function getAllCommandHandlers(string $cmd, string $channel): array {
		$handlers = [];
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
		return $handlers;
	}

	/**
	 * @HandlesCommand("intransaction")
	 */
	public function inTransactionCommand(CmdContext $context): void {
		if ($this->db->inTransaction()) {
			$msg = "There is an active transaction.";
		} else {
			$msg = "There is no active transaction.";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("rollbacktransaction")
	 */
	public function rollbackTransactionCommand(CmdContext $context): void {
		$this->db->rollback();

		$msg = "The active transaction has been rolled back.";
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("stacktrace")
	 */
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

	/**
	 * @HandlesCommand("cmdhandlers")
	 */
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

	/**
	 * @HandlesCommand("makeitem")
	 */
	public function makeItemCommand(CmdContext $context, int $lowId, int $highId, int $ql, string $name): void {
		$context->reply($this->text->makeItem($lowId, $highId, $ql, $name));
	}

	/**
	 * @HandlesCommand("createblob")
	 */
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
