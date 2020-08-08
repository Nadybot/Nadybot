<?php declare(strict_types=1);

namespace Nadybot\Core;

class GuildChannelCommandReply implements CommandReply {
	private Nadybot $chatBot;

	public function __construct(Nadybot $chatBot) {
		$this->chatBot = $chatBot;
	}

	/**
	 * @inheritDoc
	 */
	public function reply($msg): void {
		$this->chatBot->sendGuild($msg);
	}
}
