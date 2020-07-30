<?php

namespace Nadybot\Core;

class PrivateChannelCommandReply implements CommandReply {
	private $chatBot;
	private $channel;

	public function __construct(Nadybot $chatBot, $channel) {
		$this->chatBot = $chatBot;
		$this->channel = $channel;
	}

	public function reply($msg) {
		$this->chatBot->sendPrivate($msg, false, $this->channel);
	}
}
