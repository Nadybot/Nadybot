<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

/** We are leaving a private channel */
class LeavePrivEvent extends JoinLeaveEvent {
	public const EVENT_MASK = 'extleavepriv';

	/**
	 * @param string $sender  Either the name of the sender or the numeric UID (e.g. city raid announcements)
	 * @param string $channel The channel (msg, priv, guild) via which the message was sent
	 */
	public function __construct(
		public string $sender,
		public string $channel,
		public ?string $worker=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
