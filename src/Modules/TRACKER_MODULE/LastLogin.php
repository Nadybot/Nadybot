<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\DBRow;

class LastLogin extends DBRow {
	public int $uid;
	public int $dt;
}
