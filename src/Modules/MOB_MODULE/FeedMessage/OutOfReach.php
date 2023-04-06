<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class OutOfReach extends Base {
	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		public int $instance,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		$result = clone $mob;
		if ($mob->instance === $this->instance) {
			$result->status = Mob::STATUS_OUT_OF_RANGE;
			$result->last_seen = time();
		}
		return $result;
	}
}
