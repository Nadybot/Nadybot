<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'mob-attacked')]
class MobAttackedEvent extends MobEvent {
}
