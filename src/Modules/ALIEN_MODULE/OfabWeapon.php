<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class OfabWeapon extends DBRow {
	public function __construct(
		public int $type=0,
		public string $name='',
	) {
	}
}
