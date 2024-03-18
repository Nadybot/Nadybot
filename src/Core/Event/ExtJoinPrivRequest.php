<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

use Nadybot\Core\Event;

class ExtJoinPrivRequest extends Event {
	public const EVENT_MASK = "extjoinprivrequest";

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
		$this->type = self::EVENT_MASK;
	}
}
