<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

/** A user joined a room */
class Join extends InPackage {
	/**
	 * @param string $type Type of this message
	 * @param string $room ID/name of the room
	 * @param string $user UUID of the user that joined
	 */
	public function __construct(
		string $type,
		public string $room,
		public string $user,
	) {
		parent::__construct($type);
	}
}
