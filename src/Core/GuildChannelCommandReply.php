<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;

class GuildChannelCommandReply implements CommandReply, MessageEmitter {
	private Nadybot $chatBot;

	public function __construct(Nadybot $chatBot) {
		$this->chatBot = $chatBot;
	}

	public function getChannelName(): string {
		return Source::ORG;
	}

	/** @inheritDoc */
	public function reply($msg): void {
		$this->chatBot->sendGuild($msg);
	}
}
