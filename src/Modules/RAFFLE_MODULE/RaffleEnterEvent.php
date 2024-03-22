<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

class RaffleEnterEvent extends RaffleParticipationEvent {
	public const EVENT_MASK = 'raffle(enter)';

	public function __construct(
		public Raffle $raffle,
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
