<?php declare(strict_types=1);

namespace Nadybot\Core;

class PrivateMessageCommandReply implements CommandReply {
	private Nadybot $chatBot;
	private string $sender;

	public function __construct(Nadybot $chatBot, string $sender) {
		$this->chatBot = $chatBot;
		$this->sender = $sender;
	}

	public function reply($msg): void {
		$this->chatBot->sendTell($msg, $this->sender);
	}
}
