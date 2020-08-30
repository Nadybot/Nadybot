<?php declare(strict_types=1);

namespace Nadybot\Modules\ALIEN_MODULE;

use Nadybot\Core\DBRow;

class AlienWeapon extends DBRow {
	public int $type = 0;
	public string $name = '';
}
