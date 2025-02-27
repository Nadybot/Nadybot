<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\{CommandReply, MessageEmitter};

class PrivateChannelCommandReply implements CommandReply, MessageEmitter {
	public function __construct(
		private Nadybot $chatBot,
		private string $channel
	) {
	}

	public function getChannelName(): string {
		return Source::PRIV . "({$this->channel})";
	}

	/** {@inheritDoc} */
	public function reply(string|array $msg): void {
		$this->chatBot->sendPrivate(
			message: $msg,
			disableRelay: false,
			group: $this->channel
		);
	}
}
