<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class ApiSite {
	public function __construct(
		public int $playfield_id,
		public string $playfield_long_name,
		public string $playfield_short_name,
		public int $site_number,
		public ?int $ql,
		public int $min_ql,
		public int $max_ql,
		public int $x_coord,
		public int $y_coord,
		public ?string $org_name,
		public ?int $penalty_duration,
		public ?int $penalty_until,
		public ?int $org_id,
		public ?string $faction,
		public string $site_name,
		public ?int $close_time,
		public ?int $created_at,
		public int $enabled,
		public string $source="api",
	) {
	}
}
