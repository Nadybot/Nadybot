<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class Death extends Base {
	public function processUpdate(Mob $mob): Mob {
		$result = clone $mob;
		$result->status = Mob::STATUS_DOWN;
		$result->hp_percent = null;
		$result->instance = null;
		$result->last_killed = time();
		return $result;
	}
}
