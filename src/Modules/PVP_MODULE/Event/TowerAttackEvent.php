<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Attributes\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

#[Event(mask: 'tower-attack')]
class TowerAttackEvent {
	public function __construct(
		public FeedMessage\TowerAttack $attack,
	) {
	}
}
