<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

abstract class RaffleParticipationEvent extends RaffleEvent {
	public const EVENT_MASK = 'raffle(*)';

	public function __construct(
		public Raffle $raffle,
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
