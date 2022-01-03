<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Nadybot\Core\Routing\Character;

class CmdContext implements CommandReply {
	public string $message = "";
	public string $channel = "tell";
	public Character $char;
	public CommandReply $sendto;
	/** @var mixed[] */
	public array $args = [];
	public bool $forceSync = false;

	/** @var array<Closure> */
	public array $shutdownFunctions = [];

	public function __construct(string $charName, ?int $charId=null) {
		$this->char = new Character($charName, $charId);
	}

	public function reply($msg): void {
		$this->sendto->reply($msg);
	}

	/** Check if we received this from a direct message of any form */
	public function isDM(): bool {
		return in_array($this->channel, ["tell", "msg"]);
	}

	public function registerShutdownFunction(Closure $callback): void {
		$this->shutdownFunctions []= $callback;
	}

	public function __destruct() {
		foreach ($this->shutdownFunctions as $callback) {
			$callback();
		}
	}
}
