<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

class RaffleLeaveEvent extends RaffleParticipationEvent {
	public const EVENT_MASK = 'raffle(leave)';

	public function __construct(
		public Raffle $raffle,
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
