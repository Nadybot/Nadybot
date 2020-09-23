<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Event;

class RaffleParticipationEvent extends RaffleEvent {
	public string $player;
}
