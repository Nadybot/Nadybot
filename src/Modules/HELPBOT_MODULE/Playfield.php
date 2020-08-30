<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class Playfield extends DBRow {
	public int $id;
	public string $long_name;
	public ?string $short_name;
}
