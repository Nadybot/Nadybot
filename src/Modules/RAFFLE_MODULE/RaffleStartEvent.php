<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\Event;

class RaffleStartEvent extends Event {
	public const EVENT_MASK = "raffle(start)";

	public function __construct(
		public Raffle $raffle,
	) {
		$this->type = self::EVENT_MASK;
	}
}
