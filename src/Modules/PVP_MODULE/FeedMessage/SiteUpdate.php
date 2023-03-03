<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Modules\PVP_MODULE\Attributes\CastToTiming;

class SiteUpdate {
	public const TIMING_DYNAMIC = 0;
	public const TIMING_US = 1;
	public const TIMING_EU = 2;

	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public bool $enabled,
		public int $min_ql,
		public int $max_ql,
		public string $name,
		#[CastToTiming] public int $timing,
		public Coordinates $center,
		public int $num_conductors=0,
		public ?Coordinates $ct_pos=null,
		public int $num_turrets=0,
		public ?int $gas=null,
		public ?string $org_faction=null,
		public ?int $org_id=null,
		public ?string $org_name=null,
		public ?int $plant_time=null,
		public ?int $ql=null,
	) {
	}
}
