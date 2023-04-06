<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{Attacker, AttackerOrg, Coordinates, DefenderOrg, TowerAttack};

class DBTowerAttack extends DBRow {
	public int $timestamp;
	public int $playfield_id;
	public int $location_x;
	public int $location_y;
	public int $site_id;
	public ?int $ql;
	public string $att_name;
	public ?string $att_faction=null;
	public ?string $att_org=null;
	public ?int $att_org_id=null;
	public ?int $att_level=null;
	public ?int $att_ai_level=null;
	public ?string $att_profession=null;
	public ?string $att_org_rank=null;
	public ?string $att_breed=null;
	public ?string $att_gender=null;
	public ?int $att_uid=null;
	public string $def_faction;
	public string $def_org;
	public ?int $penalizing_ended=null;

	public static function fromTowerAttack(TowerAttack $att): self {
		$obj = new self();
		$obj->timestamp = $att->timestamp;
		$obj->playfield_id = $att->playfield_id;
		$obj->site_id = $att->site_id;
		$obj->ql = $att->ql;
		$obj->location_x = $att->location->x;
		$obj->location_y = $att->location->y;
		$obj->att_name = $att->attacker->name;
		$obj->att_faction = $att->attacker->org?->faction ?? $att->attacker->faction;
		$obj->att_org = $att->attacker->org?->name;
		$obj->att_org_id = $att->attacker->org?->id;
		$obj->att_org_rank = $att->attacker->org_rank;
		$obj->att_level = $att->attacker->level;
		$obj->att_ai_level = $att->attacker->ai_level;
		$obj->att_profession = $att->attacker->profession;
		$obj->att_gender = $att->attacker->gender;
		$obj->att_breed = $att->attacker->breed;
		$obj->def_faction = $att->defender->faction;
		$obj->def_org = $att->defender->name;
		$obj->penalizing_ended = $att->penalizing_ended;

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
				faction: $this->att_faction,
				org: isset($this->att_org, $this->att_faction)
					? new AttackerOrg(
						name: $this->att_org,
						faction: $this->att_faction,
						id: $this->att_org_id,
					) : null,
			),
			defender: new DefenderOrg(
				faction: $this->def_faction,
				name: $this->def_org,
			),
		);
	}
}
