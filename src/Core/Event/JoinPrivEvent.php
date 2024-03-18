<?php declare(strict_types=1);

namespace Nadybot\Core\Event;

class JoinPrivEvent extends JoinLeaveEvent {
	public const EVENT_MASK = "extjoinpriv";

	/**
	 * @param string $sender  Either the name of the sender or the numeric UID (eg. city raid accouncements)
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
