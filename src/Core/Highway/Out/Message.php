<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

/** Send a message to a highway room */
class Message extends OutPackage {
	/**
	 * @param string                     $room ID/name of the room to send a message to
	 * @param string|array<string,mixed> $body The actual message to send
	 * @param null|int|string            $id   ID of this message. Will be given back in replies.
	 *                                         (highway 0.2 only, auto-generated on `null`)
	 */
	public function __construct(
		public string $room,
		public string|array|object $body,
		null|int|string $id=null,
	) {
		parent::__construct(self::MESSAGE, $id);
	}
}
