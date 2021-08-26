<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateTime;
use Nadybot\Core\DBRow;

class ICCArbiter extends DBRow {
	public int $id;
	public string $type;
	public DateTime $start;
	public DateTime $end;
}
