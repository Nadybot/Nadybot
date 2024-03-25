<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class SkillBuffItemCount extends DBRow {
	public function __construct(
		public string $skill,
		public int $num,
	) {
	}
}
