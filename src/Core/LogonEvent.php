<?php declare(strict_types=1);

namespace Nadybot\Core;

/** A character on our buddylist logs on */
class LogonEvent extends UserStateEvent {
	public const EVENT_MASK = 'logon';

	public function __construct(
		public string $sender,
		public int $uid,
		public ?bool $wasOnline=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
