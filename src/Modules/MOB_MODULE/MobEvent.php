<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Event;

abstract class MobEvent extends Event {
	public const EVENT_MASK = 'mob-*';

	public Mob $mob;
}
