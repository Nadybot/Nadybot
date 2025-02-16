<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Attributes\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

#[Event(mask: 'tower-attack-info')]
class TowerAttackInfoEvent extends TowerAttackEvent {
	public function __construct(
		FeedMessage\TowerAttack $attack,
		public ?FeedMessage\SiteUpdate $site,
	) {
		parent::__construct(attack: $attack);
	}
}
