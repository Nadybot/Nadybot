<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerOutcome extends Event {
	public function __construct(
		public FeedMessage\TowerOutcome $outcome,
	) {
		$this->type = "tower-outcome";
	}
}
