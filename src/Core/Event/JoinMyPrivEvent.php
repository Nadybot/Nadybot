<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

/** Someone joins our private channel */
class JoinMyPrivEvent extends JoinPrivEvent {
	public const EVENT_MASK = "joinpriv";

	/**
	 * @param string $sender  The name of the person joning
	 * @param string $channel The name of the channel via which the message was sent (us)
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
