<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'raffle(*)')]
abstract class RaffleEvent {
	public function __construct(
		public Raffle $raffle,
	) {
	}
}
