<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class Attacker {
	public function __construct(
		public string $name,
		public ?int $character_id,
		public ?int $level,
		public ?int $ai_level,
		public ?string $profession,
		public ?string $org_rank,
		public ?string $gender,
		public ?string $breed,
		public ?string $faction,
		public ?AttackerOrg $org,
	) {
	}
}
