<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\DBSchema\Player;

class TowerAttack {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public Attacker $attacker,
		public Coordinates $location,
		public DefenderOrg $defender,
		public int $timestamp,
	) {
	}

	public function addLookups(?Player $player): void {
		$this->attacker->ai_level ??= $player?->ai_level;
		$this->attacker->breed ??= $player?->breed;
		$this->attacker->gender ??= $player?->gender;
		$this->attacker->level ??= $player?->level;
		$this->attacker->org_rank ??= $player?->guild_rank;
		$this->attacker->profession ??= $player?->profession;
	}

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		$tokens = [
			"pf-id" => $this->playfield_id,
			"att-coord-x" => $this->location->x,
			"att-coord-y" => $this->location->y,
		];
		return array_merge(
			$tokens,
			$this->attacker->getTokens(),
			$this->defender->getTokens(),
		);
	}
}
