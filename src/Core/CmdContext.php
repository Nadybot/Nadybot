<?php declare(strict_types=1);

namespace Nadybot\Core;

class CmdContext implements CommandReply {
	public string $message;
	public string $channel;
	public string $sender;
	public CommandReply $sendto;
	public array $args = [];

	public function reply($msg): void {
		$this->sendto->reply($msg);
	}
}
