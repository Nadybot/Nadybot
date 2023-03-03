<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class TowerAttack {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $timestamp,
		public Coordinates $location,
		public string $attacker,
		public ?string $attacking_faction,
		public ?string $attacking_org,
		public string $defending_faction,
		public string $defending_org,
	) {
	}
}
