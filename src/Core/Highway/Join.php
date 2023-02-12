<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

class Join extends Package {
	public function __construct(
		public string $room,
	) {
		$this->type = self::JOIN;
	}
}
