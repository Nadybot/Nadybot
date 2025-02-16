<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'raffle(cancel)')]
class RaffleCancelEvent extends RaffleEvent {
}
