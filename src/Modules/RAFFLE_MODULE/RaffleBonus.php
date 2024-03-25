<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

use Nadybot\Core\DBRow;

class RaffleBonus extends DBRow {
	public function __construct(
		public string $name,
		public int $bonus,
	) {
	}
}
