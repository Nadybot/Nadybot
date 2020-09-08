<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\DBRow;

class Organization extends DBRow {
	public int $id;
	public string $name = "Illegal Org";
	public int $num_members = 0;
	public string $faction = "Neutral";
}
