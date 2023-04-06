<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\PVP_MODULE\FeedMessage\TowerOutcome;

class DBOutcome extends DBRow {
	public int $playfield_id;
	public int $site_id;
	public int $timestamp;
	public ?string $attacker_faction=null;
	public ?string $attacker_org=null;
	public string $losing_faction;
	public string $losing_org;

	public static function fromTowerOutcome(TowerOutcome $outcome): self {
		$obj = new self();
		$obj->playfield_id = $outcome->playfield_id;
		$obj->site_id = $outcome->site_id;
		$obj->timestamp = $outcome->timestamp;
		$obj->attacker_faction = $outcome->attacker_faction;
		$obj->attacker_org = $outcome->attacker_org;
		$obj->losing_faction = $outcome->losing_faction;
		$obj->losing_org = $outcome->losing_org;

		return $obj;
	}

	public function toTowerOutcome(): TowerOutcome {
		return new TowerOutcome(...(array)$this);
	}
}
