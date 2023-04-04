<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class Corpse extends Base {
	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		public int $died_at,
		public ?int $instance,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		if (isset($this->instance)) {
			return $mob;
		}
		$result = clone $mob;
		$result->status = Mob::STATUS_DOWN;
		$result->hp_percent = null;
		$result->instance = null;
		$result->last_killed = $this->died_at;
		$result->last_seen = null;
		return $result;
	}
}
