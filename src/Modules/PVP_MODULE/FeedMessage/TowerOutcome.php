<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use EventSauce\ObjectHydrator\MapFrom;

class TowerOutcome {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $timestamp,
		#[MapFrom("attacking_faction")] public ?string $attacker_faction,
		#[MapFrom("attacking_org")] public ?string $attacker_org,
		public string $losing_faction,
		public string $losing_org,
	) {
	}
}
