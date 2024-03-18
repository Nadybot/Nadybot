<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Event;

class MobSpawnEvent extends Event {
	public const EVENT_MASK = "mob-spawn";

	public function __construct(
		public Mob $mob,
	) {
		$this->type = self::EVENT_MASK;
	}
}
