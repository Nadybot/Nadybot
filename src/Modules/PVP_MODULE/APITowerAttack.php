<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{Coordinates, TowerAttack};

class APITowerAttack {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $timestamp,
		public Coordinates $location,
		public string $attacker_name,
		public ?string $attacker_faction,
		public ?string $attacker_org,
		public ?int $attacker_level,
		public ?int $attacker_ai_level,
		public ?string $attacker_profession,
		public ?string $attacker_org_rank,
		public ?string $attacker_breed,
		public ?string $attacker_gender,
		public ?int $attacker_character_id,
		public string $defending_faction,
		public string $defending_org,
	) {
	}

	public static function fromTowerAttack(TowerAttack $attack, ?Player $player, ?int $uid): self {
		return new self(
			attacker_ai_level: $player?->ai_level,
			attacker_breed: $player?->breed,
			attacker_character_id: $uid,
			attacker_faction: $attack->attacker_faction,
			attacker_gender: $player?->gender,
			attacker_level: $player?->level,
			attacker_name: $attack->attacker_name,
			attacker_org: $attack->attacker_org,
			attacker_org_rank: $player?->guild_rank,
			attacker_profession: $player?->profession,
			defending_faction: $attack->defending_faction,
			defending_org: $attack->defending_org,
			location: clone ($attack->location),
			playfield_id: $attack->playfield_id,
			site_id: $attack->site_id,
			timestamp: $attack->timestamp,
		);
	}

	public function addLookups(?Player $player, ?int $uid): void {
		$this->attacker_ai_level ??= $player?->ai_level;
		$this->attacker_breed ??= $player?->breed;
		$this->attacker_character_id ??= $uid;
		$this->attacker_gender ??= $player?->gender;
		$this->attacker_level ??= $player?->level;
		$this->attacker_org_rank ??= $player?->guild_rank;
		$this->attacker_profession ??= $player?->profession;
	}
}
