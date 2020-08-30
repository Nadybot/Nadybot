<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBRow;

class TowerSite extends DBRow {
	public int $playfield_id;
	public int $site_number;
	public int $min_ql;
	public int $max_ql;
	public int $x_coord;
	public int $y_coord;
	public string $site_name;
}
