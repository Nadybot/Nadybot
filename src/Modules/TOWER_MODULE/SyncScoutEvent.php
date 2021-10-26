<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Modules\RELAY_MODULE\SyncEvent;

class SyncScoutEvent extends SyncEvent {
	public string $type = "sync(scout)";

	public int $playfield_id;
	public int $site_number;
	public int $scouted_on;
	public string $scouted_by;
	public ?int $ql = null;
	public ?string $org_name = null;
	public ?string $faction = null;
	public ?int $close_time = null;
	public ?int $created_at = null;

	public static function fromScoutInfo(ScoutInfo $si): self {
		$event = new self();
		$syncAttribs = [
			"playfield_id", "site_number", "scouted_on", "scouted_by",
			"ql", "org_name", "faction", "close_time", "created_at",
		];
		foreach ($syncAttribs as $attrib) {
			$event->{$attrib} = $si->{$attrib} ?? null;
		}
		return $event;
	}
}
