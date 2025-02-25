<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

/** A character on our buddylist logs on or off */
abstract class UserStateEvent {
	/**
	 * @param string    $sender    Name of the character
	 * @param int       $uid       UID of the character
	 * @param bool|null $wasOnline Was that character online before,
	 *                             and we received a second online-event?
	 *                             `null` if not applicable/unknown
	 */
	public function __construct(
		public string $sender,
		public int $uid,
		public ?bool $wasOnline=null,
	) {
	}
}
