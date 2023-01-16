<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Leave extends Package {
	public function __construct(
		public string $room,
	) {
		$this->type = self::LEAVE;
	}
}
