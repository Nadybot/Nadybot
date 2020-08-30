<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Addendum\ReflectionAnnotatedMethod;
use Nadybot\Core\{
	AccessManager,
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
	 * @Matches("/^showcmdregex (.+)$/i")
	 */
	public function showcmdregexCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = $args[1];
		
		// get all command handlers
		$handlers = $this->getAllCommandHandlers($cmd, $channel);
		
		// filter command handlers by access level
		$accessManager = $this->accessManager;
		$handlers = array_filter($handlers, function (CommandHandler $handler) use ($sender, $accessManager) {
			return $accessManager->checkAccess($sender, $handler->admin);
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
			$blob = '';
			foreach ($regexes as $regex) {
				$blob .= $regex . "\n";
			}
			$msg = $this->text->makeBlob("Regexes for $cmd ($count)", $blob);
		} else {
			$msg = "No regexes found for command <highlight>$cmd<end>.";
		}
		$sendto->reply($msg);
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
					$handlers []= $handler;
				}
			}
		}
		return $handlers;
	}
	
	/**
	 * @HandlesCommand("intransaction")
	 * @Matches("/^intransaction$/i")
	 */
	public function inTransactionCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($this->db->inTransaction()) {
			$msg = "There is an active transaction.";
		} else {
			$msg = "There is no active transaction.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("rollbacktransaction")
	 * @Matches("/^rollbacktransaction$/i")
	 */
	public function rollbackTransactionCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->db->rollback();
		
		$msg = "The active transaction has been rolled back.";
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("stacktrace")
	 * @Matches("/^stacktrace$/i")
	 */
	public function stacktraceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$stacktrace = $this->util->getStackTrace();
		$count = substr_count($stacktrace, "\n");
		$msg = $this->text->makeBlob("Current Stacktrace ($count)", $stacktrace);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("cmdhandlers")
	 * @Matches("/^cmdhandlers (.*)$/i")
	 */
	public function cmdhandlersCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmdArray = explode(" ", $args[1], 2);
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
		
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("makeitem")
	 * @Matches("/^makeitem (\d+) (\d+) (\d+) (.+)$/i")
	 */
	public function makeItemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$lowId = (int)$args[1];
		$highId = (int)$args[2];
		$ql = (int)$args[3];
		$name = $args[4];
		$sendto->reply($this->text->makeItem($lowId, $highId, $ql, $name));
	}
	
	/**
	 * @HandlesCommand("createblob")
	 * @Matches("/^createblob (\d+)$/i")
	 * @Matches("/^createblob (\d+) (\d+)$/i")
	 */
	public function createBlobCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$length = (int)$args[1];
		if (isset($args[2])) {
			$numBlobs = (int)$args[2];
		} else {
			$numBlobs = 1;
		}
		
		for ($i = 0; $i < $numBlobs; $i++) {
			$blob = $this->randString($length);
			$msg = $this->text->makeBlob("Blob $i", $blob);
			$sendto->reply($msg);
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
