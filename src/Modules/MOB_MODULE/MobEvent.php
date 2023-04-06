<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Event;

class MobEvent extends Event {
	public function __construct(
		public Mob $mob,
		public string $type,
	) {
	}
}
