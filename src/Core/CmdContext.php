<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Character;

class CmdContext implements CommandReply {
	public string $message = "";
	public string $channel = "tell";
	public Character $char;
	public CommandReply $sendto;
	public array $args = [];
	public bool $forceSync = false;

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
}