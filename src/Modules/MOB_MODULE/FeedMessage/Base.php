<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Modules\MOB_MODULE\Mob;

class Base {
	public const CORPSE = "corpse";
	public const DEATH = "death";
	public const SPAWN = "spawn";
	public const HP = "hp";
	public const OOR = "out_of_range";

	public function __construct(
		public string $type,
		public string $event,
		public string $key,
	) {
	}

	public function processUpdate(Mob $mob): Mob {
		return clone $mob;
	}
}
