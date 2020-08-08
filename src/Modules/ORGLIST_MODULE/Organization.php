<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\DBRow;

class Organization extends DBRow {
	public int $id;
	public string $name;
	public int $num_members;
	public string $faction;
}
