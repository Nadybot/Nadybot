<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class Join extends InPackage {
	public function __construct(
		public string $room,
	) {
		parent::__construct(self::JOIN);
	}
}
