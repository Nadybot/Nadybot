<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use DateTime;
use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ORGLIST_MODULE\Organization;

class TrackingOrg extends DBRow {
	public int $org_id;
	public DateTime $added_dt;
	public string $added_by;
	#[NCA\DB\Ignore]
	public ?Organization $org = null;

	public function __construct() {
		$this->added_dt = new DateTime();
	}
}
