<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class Research extends DBRow {
	public ?int $level;
	public ?int $sk;
	public ?int $levelcap;
}
