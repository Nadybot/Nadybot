<?php

namespace Nadybot\Core;

class GuildChannelCommandReply implements CommandReply {
	private $chatBot;

	public function __construct(Nadybot $chatBot) {
		$this->chatBot = $chatBot;
	}

	public function reply($msg) {
		$this->chatBot->sendGuild($msg);
	}
}
