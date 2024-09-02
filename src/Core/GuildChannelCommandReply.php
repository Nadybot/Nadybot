<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\{CommandReply, MessageEmitter};

class GuildChannelCommandReply implements CommandReply, MessageEmitter {
	public function __construct(
		private Nadybot $chatBot
	) {
	}

	public function getChannelName(): string {
		return Source::ORG;
	}

	/** @inheritDoc */
	public function reply(string|array $msg): void {
		$this->chatBot->sendGuild(Blob::renderMulti(text: $msg, formatMessage: false));
	}
}
