<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We receive a message on a private channel */
#[Event(mask: 'extpriv')]
class PrivateChannelMsgEvent extends AOChatEvent {
	/**
	 * @param string  $sender  The name of the sender of the message
	 * @param string  $channel The name of the private channel via which the message was sent
	 * @param string  $message The message itself
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		string $channel,
		string $message,
		?string $worker=null,
	) {
		parent::__construct(
			channel: $channel,
			message: $message,
			worker: $worker
		);
	}
}
