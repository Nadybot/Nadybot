<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class DynaDB extends DBRow {
	public int $playfield_id;
	public string $mob;
	public int $min_ql;
	public int $max_ql;
	public int $x_coord;
	public int $y_coord;
}
