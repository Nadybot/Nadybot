<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBRow;

class HotApiSite extends DBRow {
	public int $id;
	public int $playfield_id;
	public int $site_number;
	public int $ql;
	public string $org_name;
	public int $org_id;
	public string $faction;
	public int $close_time;
	public int $close_time_override;
	public int $created_at;
}
