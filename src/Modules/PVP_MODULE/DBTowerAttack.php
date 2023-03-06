<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Modules\PVP_MODULE\FeedMessage\{Coordinates, TowerAttack};

class DBTowerAttack extends DBRow {
	public int $timestamp;
	public int $playfield_id;
	public int $location_x;
	public int $location_y;
	public int $site_id;
	public string $att_name;
	public ?string $att_faction=null;
	public ?string $att_org=null;
	public ?int $att_level=null;
	public ?int $att_ai_level=null;
	public ?string $att_profession=null;
	public ?string $att_org_rank=null;
	public ?string $att_breed=null;
	public ?string $att_gender=null;
	public ?int $att_uid=null;
	public string $def_faction;
	public string $def_org;

	public static function fromTowerAttack(TowerAttack $att, ?Player $player, ?int $uid): self {
		$obj = new self();
		$obj->timestamp = $att->timestamp;
		$obj->playfield_id = $att->playfield_id;
		$obj->site_id = $att->site_id;
		$obj->location_x = $att->location->x;
		$obj->location_y = $att->location->y;
		$obj->att_name = $att->attacker_name;
		$obj->att_faction = $att->attacker_faction;
		$obj->att_org = $att->attacker_org;
		if (isset($player)) {
			$obj->att_faction ??= $player->faction;
			$obj->att_level = $player->level;
			$obj->att_ai_level = $player->ai_level;
			$obj->att_profession = $player->profession;
			$obj->att_gender = $player->gender;
			$obj->att_org_rank = $player->guild_rank;
			$obj->att_breed = $player->breed;
		}
		$obj->att_uid = $uid;
		$obj->def_faction = $att->defending_faction;
		$obj->def_org = $att->defending_org;

		return $obj;
	}

	public static function fromAPITowerAttack(APITowerAttack $att): self {
		$obj = new self();
		$obj->att_ai_level = $att->attacker_ai_level;
		$obj->att_breed = $att->attacker_breed;
		$obj->att_faction = $att->attacker_faction;
		$obj->att_gender = $att->attacker_gender;
		$obj->att_level = $att->attacker_level;
		$obj->att_name = $att->attacker_name;
		$obj->att_org = $att->attacker_org;
		$obj->att_org_rank = $att->attacker_org_rank;
		$obj->att_profession = $att->attacker_profession;
		$obj->att_uid = $att->attacker_character_id;
		$obj->def_faction = $att->defending_faction;
		$obj->def_org = $att->defending_org;
		$obj->location_x = $att->location->x;
		$obj->location_y = $att->location->y;
		$obj->playfield_id = $att->playfield_id;
		$obj->site_id = $att->site_id;
		$obj->timestamp = $att->timestamp;

		return $obj;
	}

	public function toTowerAttack(): TowerAttack {
		return new TowerAttack(
			timestamp: $this->timestamp,
			playfield_id: $this->playfield_id,
			site_id: $this->site_id,
			location: new Coordinates($this->location_x, $this->location_y),
			attacker_name: $this->att_name,
			attacker_faction: $this->att_faction,
			attacker_org: $this->att_org,
			defending_faction: $this->def_faction,
			defending_org: $this->def_org,
		);
	}
}
