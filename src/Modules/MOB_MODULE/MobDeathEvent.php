<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'mob-death')]
class MobDeathEvent extends MobEvent {
}
