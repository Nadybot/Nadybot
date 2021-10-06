<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\DBRow;

class TrackingOrgMember extends DBRow {
	public int $org_id;
	public int $uid;
	public string $name;
	public bool $hidden=false;
}
