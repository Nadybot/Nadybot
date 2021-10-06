<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use DateTime;
use Nadybot\Core\DBRow;

class TrackingOrg extends DBRow {
	public int $org_id;
	public DateTime $added_dt;
	public string $added_by;

	public function __construct() {
		$this->added_dt = new DateTime();
	}
}
