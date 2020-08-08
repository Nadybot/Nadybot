<?php

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\CommandManager;
use Nadybot\Core\Nadybot;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'demo',
 *		accessLevel = 'all',
 *		description = 'Execute a command so that links will execute in the same channel',
 *		help        = 'demo.txt'
 *	)
 */
class SameChannelResponseController {
	
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public Nadybot $chatBot;
	
	/**
	 * @HandlesCommand("demo")
	 * @Matches("/^demo (.+)$/si")
	 */
	public function demoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$commandString = $args[1];
		$customSendto = new DemoResponseCommandReply($channel, $sendto, $this->chatBot->vars["name"]);
		$this->commandManager->process($channel, $commandString, $sender, $customSendto);
	}
}

class DemoResponseCommandReply implements CommandReply {
	private CommandReply $sendto;
	private string $channel;
	private string $botname;
	
	public function __construct(string $channel, CommandReply $sendto, string $botname) {
		$this->channel = $channel;
		$this->sendto = $sendto;
		$this->botname = $botname;
	}

	public function reply($msg): void {
		if ($this->channel == 'priv') {
			$msg = str_replace("chatcmd:///tell {$this->botname} ", "chatcmd:///g {$this->botname} <symbol>demo ", $msg);
		} elseif ($this->channel == 'guild') {
			$msg = str_replace("chatcmd:///tell {$this->botname} ", "chatcmd:///o <symbol>demo ", $msg);
		}
		$this->sendto->reply($msg);
	}
}
