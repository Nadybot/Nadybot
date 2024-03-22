<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerOutcomeEvent extends Event {
	public const EVENT_MASK = 'tower-outcome';

	public function __construct(
		public FeedMessage\TowerOutcome $outcome,
	) {
		$this->type = self::EVENT_MASK;
	}
}
