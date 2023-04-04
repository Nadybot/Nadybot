<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class Spawn extends Base {
	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		public string $name,
		public int $instance,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		$result = clone $mob;
		$result->status = Mob::STATUS_UP;
		$result->hp_percent = 100.00;
		$result->last_killed = null;
		$result->instance = $this->instance;
		$result->name = $this->name;
		$result->last_seen = null;
		$result->fixName();
		return $result;
	}
}
