<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRemscoutEvent extends SyncEvent {
	public string $type = "sync(remscout)";

	public int $playfield_id;
	public int $site_number;
	public string $scouted_by;
}
