<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

class JoinPrivEvent extends JoinLeaveEvent {
	public const EVENT_MASK = 'extjoinpriv';

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
