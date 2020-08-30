<?php declare(strict_types=1);

namespace Nadybot\Modules\RAFFLE_MODULE;

class RaffleResultItem {
	public string $player;
	public int $points = 500;
	public int $bonus_points = 0;
	public bool $won = false;

	public function __construct(string $player) {
		$this->player = $player;
	}

	public function decreasePoints(): int {
		return ($this->points = max(0, $this->points-1));
	}

	public function increasePoints(): int {
		return ++$this->points;
	}
}
