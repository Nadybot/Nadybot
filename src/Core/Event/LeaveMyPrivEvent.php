<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

/** Someone leaves our private channel */
class LeaveMyPrivEvent extends LeavePrivEvent {
	public const EVENT_MASK = "leavepriv";

	/**
	 * @param string $sender  The name of the person leaving
	 * @param string $channel The name of the channel via which the message was sent (us)
	 */
	public function __construct(
		public string $sender,
		public string $channel,
	) {
		$this->type = self::EVENT_MASK;
	}
}
