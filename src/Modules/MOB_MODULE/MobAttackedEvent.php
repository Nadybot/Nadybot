<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Event;

class MobAttackedEvent extends Event {
	public const EVENT_MASK = "mob-attacked";

	public function __construct(
		public Mob $mob,
	) {
		$this->type = self::EVENT_MASK;
	}
}
