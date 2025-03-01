<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

/** Someone sends a message in a room */
class Message extends InPackage {
	/**
	 * @param string                     $type The package type
	 * @param string                     $room The ID/name of the room where the message was sent
	 * @param string|array<string,mixed> $body The actual message that was sent
	 * @param string                     $user The UUID of the user who sent the message
	 */
	public function __construct(
		string $type,
		public string $room,
		public string|array|object $body,
		public string $user,
	) {
		parent::__construct($type);
	}
}
