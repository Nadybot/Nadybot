<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, PK, Table};
use Nadybot\Core\{DBTable, Types\Faction, Types\Playfield};
use Nadybot\Modules\PVP_MODULE\FeedMessage\TowerOutcome;

#[Table(name: 'nw_outcomes')]
class DBOutcome extends DBTable {
	public function __construct(
		#[PK] #[ColName('playfield_id')] public Playfield $playfield,
		#[PK] public int $site_id,
		#[PK] public int $timestamp,
		public Faction $losing_faction,
		public string $losing_org,
		public ?Faction $attacker_faction=null,
		public ?string $attacker_org=null,
	) {
	}

	public static function fromTowerOutcome(TowerOutcome $outcome): self {
		$obj = new self(
			playfield: $outcome->playfield,
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
		$array = get_object_vars($this);
		// @mago-ignore analysis:too-few-arguments
		return new TowerOutcome(...$array);
	}
}
