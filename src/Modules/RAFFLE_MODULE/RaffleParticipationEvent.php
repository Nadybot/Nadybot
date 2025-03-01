<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'raffle(*)')]
abstract class RaffleParticipationEvent extends RaffleEvent {
	public function __construct(
		Raffle $raffle,
		public string $player,
	) {
		parent::__construct(raffle: $raffle);
	}
}
