<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\{
	Routing\Source,
	Types\CommandReply,
	Types\MessageEmitter
};

/** A CommandReply for sending messages to the org's channel */
class GuildChannelCommandReply implements CommandReply, MessageEmitter {
	public function __construct(
		private Nadybot $chatBot
	) {
	}

	/** {@inheritDoc} */
	public function getChannelName(): string {
		return Source::ORG;
	}

	/** {@inheritDoc} */
	public function reply(string|array $msg): void {
		$this->chatBot->sendGuild(message: $msg);
	}
}
