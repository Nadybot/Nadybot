<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerAttackInfo extends TowerAttack {
	public const EVENT_MASK = "tower-attack-info";

	public function __construct(
		public FeedMessage\TowerAttack $attack,
		public ?FeedMessage\SiteUpdate $site,
	) {
		$this->type = self::EVENT_MASK;
	}
}
