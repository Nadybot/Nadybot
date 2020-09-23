<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Event;

class TowerAttackEvent extends Event {
	public Player $attacker;
	public object $defender;
	public TowerSite $site;
	public string $type = "tower(attack)";
}
