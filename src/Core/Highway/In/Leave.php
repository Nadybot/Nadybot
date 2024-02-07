<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Leave extends InPackage {
	public function __construct(
		public string $room,
		public string $user,
	) {
		parent::__construct(self::LEAVE);
	}
}
