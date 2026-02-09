<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{Safe, Types\CommandReply};

class DemoResponseCommandReply implements CommandReply {
	public function __construct(
		private string $source,
		private CommandReply $sendto,
		private string $botname
	) {
	}

	/** @param string|list<string> $msg */
	public function reply(string|array $msg): void {
		if ($this->source === 'aopriv') {
			/** @psalm-suppress PossiblyInvalidArgument */
			$msg = str_replace("chatcmd:///tell {$this->botname} ", 'chatcmd:///g <myname> <symbol>demo ', $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', 'chatcmd:///g <myname> <symbol>demo ', $msg);
		} elseif (count($matches = Safe::pregMatch("/^aopriv\((.+)\)$/", $this->source))) {
			/** @psalm-suppress PossiblyInvalidArgument */
			$msg = str_replace("chatcmd:///tell {$this->botname} ", "chatcmd:///g {$matches[1]} <symbol>demo ", $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', "chatcmd:///g {$matches[1]} <symbol>demo ", $msg);
		} elseif ($this->source === 'aoorg') {
			/** @psalm-suppress PossiblyInvalidArgument */
			$msg = str_replace("chatcmd:///tell {$this->botname} ", 'chatcmd:///o <symbol>demo ', $msg);
			$msg = str_replace('chatcmd:///tell <myname> ', 'chatcmd:///o <symbol>demo ', $msg);
		}

		/** @var string|list<string> $msg */
		$this->sendto->reply($msg);
	}
}
