<?php

namespace Nadybot\Core;

class PrivateMessageCommandReply implements CommandReply {
	private $chatBot;
	private $sender;

	public function __construct(Nadybot $chatBot, $sender) {
		$this->chatBot = $chatBot;
		$this->sender = $sender;
	}

	public function reply($msg) {
		$this->chatBot->sendTell($msg, $this->sender);
	}
}
