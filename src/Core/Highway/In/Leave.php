<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

/** Someone left a room */
class Leave extends InPackage {
	/**
	 * @param string $type Package type
	 * @param string $room The name/ID of the room
	 * @param string $user The UUID of the user who left
	 */
	public function __construct(
		string $type,
		public string $room,
		public string $user,
	) {
		parent::__construct($type);
	}
}
