<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{DBRow, Faction};
use Nadybot\Modules\PVP_MODULE\FeedMessage\TowerOutcome;

class DBOutcome extends DBRow {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $timestamp,
		public Faction $losing_faction,
		public string $losing_org,
		public ?Faction $attacker_faction=null,
		public ?string $attacker_org=null,
	) {
	}

	public static function fromTowerOutcome(TowerOutcome $outcome): self {
		$obj = new self(
			playfield_id: $outcome->playfield_id,
			site_id: $outcome->site_id,
			timestamp: $outcome->timestamp,
			losing_faction: $outcome->losing_faction,
			losing_org: $outcome->losing_org,
			attacker_faction: $outcome->attacker_faction,
			attacker_org: $outcome->attacker_org,
		);

		return $obj;
	}

	public function toTowerOutcome(): TowerOutcome {
		return new TowerOutcome(...(array)$this);
	}
}
