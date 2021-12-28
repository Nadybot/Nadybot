<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Modules\HELPBOT_MODULE\Playfield;

class TowerVictoryPlus extends TowerAttackAndVictory {
	public int $victory_time;
	public int $attack_time;
	public ?Playfield $pf = null;
	public ?TowerSite $site = null;
}
