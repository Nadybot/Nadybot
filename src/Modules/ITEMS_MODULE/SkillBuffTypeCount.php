<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class SkillBuffTypeCount extends DBRow {
	public string $item_type;
	public int $num;
}
