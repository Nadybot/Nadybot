<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBRow;

class TowerAttackAndVictory extends DBRow {
	use TowerAttackTrait;
	use TowerVictoryTrait;
}
