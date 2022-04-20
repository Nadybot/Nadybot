<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class ScoutInfoPlus extends ScoutInfo {
	public string $playfield_long_name;
	public string $playfield_short_name;
	public int $min_ql;
	public int $max_ql;
	public int $x_coord;
	public int $y_coord;
	public ?int $org_id;
	public string $site_name;
	public int $enabled = 1;
}
