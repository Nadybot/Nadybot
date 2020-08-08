<?php declare(strict_types=1);

namespace Nadybot\Core;

class PrivateChannelCommandReply implements CommandReply {
	private Nadybot $chatBot;
	private string $channel;

	public function __construct(Nadybot $chatBot, string $channel) {
		$this->chatBot = $chatBot;
		$this->channel = $channel;
	}

	public function reply($msg): void {
		$this->chatBot->sendPrivate($msg, false, $this->channel);
	}
}
