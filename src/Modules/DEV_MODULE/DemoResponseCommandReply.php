<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{CommandReply, Safe};

class DemoResponseCommandReply implements CommandReply {
	private CommandReply $sendto;
	private string $source;
	private string $botname;

	public function __construct(string $source, CommandReply $sendto, string $botname) {
		$this->source = $source;
		$this->sendto = $sendto;
		$this->botname = $botname;
	}

	public function reply($msg): void {
		if ($this->source === 'aopriv') {
			$msg = str_replace("chatcmd:///tell {$this->botname} ", 'chatcmd:///g <myname> <symbol>demo ', $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', 'chatcmd:///g <myname> <symbol>demo ', $msg);
		} elseif (count($matches = Safe::pregMatch("/^aopriv\((.+)\)$/", $this->source))) {
			$msg = str_replace("chatcmd:///tell {$this->botname} ", "chatcmd:///g {$matches[1]} <symbol>demo ", $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', "chatcmd:///g {$matches[1]} <symbol>demo ", $msg);
		} elseif ($this->source === 'aoorg') {
			$msg = str_replace("chatcmd:///tell {$this->botname} ", 'chatcmd:///o <symbol>demo ', $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', 'chatcmd:///o <symbol>demo ', $msg);
		}
		$this->sendto->reply($msg);
	}
}
