<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class Pocketboss extends DBRow {
	public int $id;
	public string $pb;
	public string $pb_location;
	public string $bp_mob;
	public int $bp_lvl;
	public string $bp_location;
	public string $type;
	public string $slot;
	public string $line;
	public int $ql;
	public int $itemid;
}
