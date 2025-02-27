<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\{StringableTrait, Types\Playfield};

class TowerAttack {
	use StringableTrait;

	/** @var array<string,null|string|int> */
	public const EXAMPLE_TOKENS = [
		...Attacker::EXAMPLE_TOKENS,
		...DefenderOrg::EXAMPLE_TOKENS,
		'pf-id' => 551,
		'att-coord-x' => 700,
		'att-coord-y' => 800,
	];

	public bool $isFake = false;

	public function __construct(
		#[MapFrom('playfield_id')] public Playfield $playfield,
		public int $site_id,
		public ?int $ql,
		public Attacker $attacker,
		public Coordinates $location,
		public DefenderOrg $defender,
		public int $timestamp,
		public ?int $penalizing_ended,
	) {
		$this->isFake = !isset($attacker->character_id)
			|| (!isset($attacker->org) && !isset($attacker->level));
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

	/** @return array<string,null|string|int> */
	public function getTokens(): array {
		$tokens = [
			'pf-id' => $this->playfield->value,
			'att-coord-x' => $this->location->x,
			'att-coord-y' => $this->location->y,
		];
		return array_merge(
			$tokens,
			$this->attacker->getTokens(),
			$this->defender->getTokens(),
		);
	}
}
