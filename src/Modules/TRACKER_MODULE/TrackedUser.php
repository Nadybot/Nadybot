<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\DBRow;

class TrackedUser extends DBRow {
	public int $uid;
	public string $name;
	public string $added_by;
	public int $added_dt;
}
