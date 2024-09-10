<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\{CommandReply, MessageEmitter};

class PrivateMessageCommandReply implements CommandReply, MessageEmitter {
	public function __construct(
		private Nadybot $chatBot,
		private string $sender,
		private ?int $worker=null
	) {
	}

	public function getChannelName(): string {
		return Source::TELL . "({$this->sender})";
	}

	/** @inheritDoc */
	public function reply(string|array $msg): void {
		if (isset($this->worker)) {
			$this->chatBot->sendMassTell(
				message: $msg,
				character: $this->sender,
				worker: $this->worker,
			);
		} else {
			$this->chatBot->sendTell(
				message: $msg,
				character: $this->sender,
			);
		}
	}
}
