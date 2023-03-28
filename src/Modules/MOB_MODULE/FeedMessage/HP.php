<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class HP extends Base {
	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		public float $hp_percent,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		$result = clone $mob;
		$result->status = ($this->hp_percent >= 100.00)
			? Mob::STATUS_UP
			: Mob::STATUS_ATTACKED;
		$result->hp_percent = $this->hp_percent;
		$result->last_killed = null;
		return $result;
	}
}
