<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We send a message to a private channel */
#[Event(mask: 'sendpriv')]
class SendPrivEvent extends AOChatEvent {
	/**
	 * @param string      $sender  Either the name of the sender or the numeric UID (e.g. city raid announcements)
	 * @param string      $channel The channel (msg, priv, guild) via which the message was sent
	 * @param string      $message The message itself
	 * @param null|string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		string $channel,
		string $message,
		?string $worker=null,
	) {
		parent::__construct(channel: $channel, message: $message, worker: $worker);
	}
}
