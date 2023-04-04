<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class Death extends Base {
	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		public int $instance,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		if ($mob->instance !== $this->instance) {
			return $mob;
		}
		$result = clone $mob;
		$result->status = Mob::STATUS_DOWN;
		$result->hp_percent = null;
		$result->instance = null;
		$result->last_killed = time();
		$result->last_seen = null;
		return $result;
	}
}
