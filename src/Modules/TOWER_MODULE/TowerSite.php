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
	public ?int $x_coord1 = null;
	public ?int $x_coord2 = null;
	public ?int $y_coord1 = null;
	public ?int $y_coord2 = null;
	public string $site_name;

	/**
	 * Is this legacy (0), EU-friendly (1) or US-friendly (2)
	 */
	public int $timing;
}
