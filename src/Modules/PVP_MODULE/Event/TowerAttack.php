<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerAttack extends Event {
	public function __construct(
		public FeedMessage\TowerAttack $attack,
	) {
		$this->type = "tower-attack";
	}
}
