<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\DBRow;

class NameHistory extends DBRow {
	public int $charid;
	public string $name;
	public int $dimension;
	public int $dt;
}
