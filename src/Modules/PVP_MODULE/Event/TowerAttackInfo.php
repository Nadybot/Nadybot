<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerAttackInfo extends TowerAttack {
	public function __construct(
		public FeedMessage\TowerAttack $attack,
		public ?Player $attacker,
		public ?FeedMessage\SiteUpdate $site,
	) {
		$this->type = "tower-attack-info";
	}
}
