<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'mob-*')]
abstract class MobEvent {
	public function __construct(
		public Mob $mob,
	) {
	}
}
