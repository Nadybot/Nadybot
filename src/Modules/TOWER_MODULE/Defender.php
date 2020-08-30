<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Modules\HELPBOT_MODULE\Playfield;

class Defender {
	public string $faction;
	public string $guild;
	public Playfield $playfield;
	public ?TowerSite $site;
}
