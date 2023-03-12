<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\DBSchema\Player;

class TowerAttack {
	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		...Attacker::EXAMPLE_TOKENS,
		...DefenderOrg::EXAMPLE_TOKENS,
		"pf-id" => 551,
		"att-coord-x" => 700,
		"att-coord-y" => 800,
	];

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
		$this->attacker->faction ??= $player?->faction;
		$this->attacker->breed ??= $player?->breed;
		$this->attacker->gender ??= $player?->gender;
		$this->attacker->level ??= $player?->level;
		if (isset($this->attacker->org)) {
			$this->attacker->org_rank ??= $player?->guild_rank;
		}
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
