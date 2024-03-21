<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ORGLIST_MODULE\Organization;
use Safe\DateTime;

class TrackingOrg extends DBRow {
	public DateTime $added_dt;

	public function __construct(
		public int $org_id,
		public string $added_by,
		?DateTime $added_dt=null,
		#[NCA\DB\Ignore]
		public ?Organization $org=null,
	) {
		$this->added_dt = $added_dt ?? new DateTime();
	}
}
