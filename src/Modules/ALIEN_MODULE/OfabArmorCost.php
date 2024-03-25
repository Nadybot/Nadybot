<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class OfabArmorCost extends DBRow {
	public function __construct(
		public string $slot,
		public int $ql,
		public int $vp,
	) {
	}
}
