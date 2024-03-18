<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Event;

class RaffleEvent extends Event {
	public const EVENT_MASK = "raffle(*)";

	public Raffle $raffle;
}
