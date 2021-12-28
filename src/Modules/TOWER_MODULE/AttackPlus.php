<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Modules\HELPBOT_MODULE\Playfield;

class AttackPlus extends TowerAttack {
	public ?TowerSite $site = null;
	public ?Playfield $pf = null;
}
