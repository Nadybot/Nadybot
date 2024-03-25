<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class OfabArmorType extends DBRow {
	public function __construct(
		public int $type,
		public string $profession,
	) {
	}
}
