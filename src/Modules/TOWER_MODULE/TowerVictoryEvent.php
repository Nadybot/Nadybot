<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\Event;

class TowerVictoryEvent extends Event {
	public ?TowerSite $site;
	public TowerAttack $attack;
	public string $type = "tower(victory)";
}
