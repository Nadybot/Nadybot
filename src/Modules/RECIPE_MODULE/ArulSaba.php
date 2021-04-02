<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\DBRow;

class ArulSaba extends DBRow {
	public string $name;
	public string $lesser_prefix;
	public string $regular_prefix;
	public string $buffs;
}
