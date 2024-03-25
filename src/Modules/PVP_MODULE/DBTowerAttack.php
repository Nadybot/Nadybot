<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{DBRow, Faction};
use Nadybot\Modules\PVP_MODULE\FeedMessage\{Attacker, AttackerOrg, Coordinates, DefenderOrg, TowerAttack};

class DBTowerAttack extends DBRow {
	public function __construct(
		public int $timestamp,
		public int $playfield_id,
		public int $location_x,
		public int $location_y,
		public int $site_id,
		public ?int $ql,
		public string $att_name,
		public Faction $def_faction,
		public string $def_org,
		public ?Faction $att_faction=null,
		public ?string $att_org=null,
		public ?int $att_org_id=null,
		public ?int $att_level=null,
		public ?int $att_ai_level=null,
		public ?string $att_profession=null,
		public ?string $att_org_rank=null,
		public ?string $att_breed=null,
		public ?string $att_gender=null,
		public ?int $att_uid=null,
		public ?int $penalizing_ended=null,
	) {
	}

	public static function fromTowerAttack(TowerAttack $att): self {
		$attFaction = $att->attacker->org?->faction ?? $att->attacker->faction;
		$obj = new self(
			timestamp: $att->timestamp,
			playfield_id: $att->playfield_id,
			site_id: $att->site_id,
			ql: $att->ql,
			location_x: $att->location->x,
			location_y: $att->location->y,
			att_name: $att->attacker->name,
			att_faction: isset($attFaction) ? Faction::from($attFaction) : null,
			att_org: $att->attacker->org?->name,
			att_org_id: $att->attacker->org?->id,
			att_org_rank: $att->attacker->org_rank,
			att_level: $att->attacker->level,
			att_ai_level: $att->attacker->ai_level,
			att_profession: $att->attacker->profession,
			att_gender: $att->attacker->gender,
			att_breed: $att->attacker->breed,
			def_faction: Faction::from($att->defender->faction),
			def_org: $att->defender->name,
			penalizing_ended: $att->penalizing_ended,
		);

		return $obj;
	}

	public function toTowerAttack(): TowerAttack {
		return new TowerAttack(
			timestamp: $this->timestamp,
			penalizing_ended: $this->penalizing_ended,
			playfield_id: $this->playfield_id,
			site_id: $this->site_id,
			ql: $this->ql,
			location: new Coordinates($this->location_x, $this->location_y),
			attacker: new Attacker(
				name: $this->att_name,
				character_id: $this->att_uid,
				level: $this->att_level,
				ai_level: $this->att_ai_level,
				profession: $this->att_profession,
				org_rank: $this->att_org_rank,
				gender: $this->att_gender,
				breed: $this->att_breed,
				faction: $this->att_faction?->value,
				org: isset($this->att_org, $this->att_faction)
					? new AttackerOrg(
						name: $this->att_org,
						faction: $this->att_faction->value,
						id: $this->att_org_id,
					) : null,
			),
			defender: new DefenderOrg(
				faction: $this->def_faction->value,
				name: $this->def_org,
			),
		);
	}
}
