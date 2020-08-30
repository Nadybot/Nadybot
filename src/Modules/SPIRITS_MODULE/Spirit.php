<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE;

use Nadybot\Core\DBRow;

class Spirit extends DBRow {
	public int $id;
	public string $name;
	public int $ql;
	public string $spot;
	public int $level;
	public int $agility;
	public int $sense;
}
