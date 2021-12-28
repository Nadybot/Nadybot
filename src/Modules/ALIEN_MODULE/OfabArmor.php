<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class OfabArmor extends DBRow {
	public string $profession;
	public string $name;
	public string $slot;
	public int $lowid;
	public int $highid;
	public int $upgrade;
}
