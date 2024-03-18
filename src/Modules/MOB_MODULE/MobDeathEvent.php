<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Event;

class MobDeathEvent extends Event {
	public const EVENT_MASK = "mob-death";

	public function __construct(
		public Mob $mob,
	) {
		$this->type = self::EVENT_MASK;
	}
}
