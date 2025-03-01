<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Fired when we are invited to another bot's private channel */
#[Event(mask: 'extjoinprivrequest')]
class ExtJoinPrivRequest {
	/**
	 * @param string  $sender  The user inviting us to  their channel
	 * @param string  $channel The channel which we were invited to
	 * @param ?string $worker  If set, this is the id of the worker via which the message was received
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public ?string $worker=null,
	) {
	}
}
