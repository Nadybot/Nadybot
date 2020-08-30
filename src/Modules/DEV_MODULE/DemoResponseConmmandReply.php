<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;

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
