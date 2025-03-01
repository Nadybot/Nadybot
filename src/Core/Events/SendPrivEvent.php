<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We send a message to a private channel (ours, or another bot's) */
#[Event(mask: 'sendpriv')]
class SendPrivEvent extends AOChatEvent {
	/**
	 * @param string $sender       Our bot's name
	 * @param string $channel      The name of the private channel on which the message was sent
	 * @param string $message      The message itself
	 * @param bool   $disableRelay Set to true if no further message forwarding should happen
	 */
	public function __construct(
		public string $sender,
		string $channel,
		string $message,
		?string $worker=null,
		public bool $disableRelay=false,
	) {
		parent::__construct(channel: $channel, message: $message, worker: $worker);
	}
}
