<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\{DBRow, Profession};

class OfabArmor extends DBRow {
	public function __construct(
		public Profession $profession,
		public string $name,
		public string $slot,
		public int $lowid,
		public int $highid,
		public int $upgrade,
	) {
	}
}
