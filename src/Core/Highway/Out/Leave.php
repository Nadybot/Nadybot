<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

/** Request to leave a highway room */
class Leave extends OutPackage {
	/**
	 * @param string          $room ID/name of the room to leave
	 * @param null|int|string $id   ID of this message. Will be given back in replies.
	 *                              (highway 0.2 only, auto-generated on `null`)
	 */
	public function __construct(
		public string $room,
		null|int|string $id=null,
	) {
		parent::__construct(self::LEAVE, $id);
	}
}
