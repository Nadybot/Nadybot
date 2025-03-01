<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Routing\Source;
use Nadybot\Core\Types\{CommandReply, MessageEmitter};

/** A message emitter and receiver for tell messages */
class PrivateMessageCommandReply implements CommandReply, MessageEmitter {
	/**
	 * @param string   $sender Who to send to, or receive messages from
	 * @param null|int $worker Via which worker to send tells, or `null` for the main account
	 */
	public function __construct(
		private Nadybot $chatBot,
		private string $sender,
		private ?int $worker=null
	) {
	}

	/** {@inheritDoc} */
	public function getChannelName(): string {
		return Source::TELL . "({$this->sender})";
	}

	/** {@inheritDoc} */
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
