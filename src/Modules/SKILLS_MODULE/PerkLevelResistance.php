<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class PerkLevelResistance extends DBRow {
	#[NCA\DB\Ignore]
	public int $perk_level;

	#[NCA\DB\Ignore]
	public ?string $nanoline=null;

	public function __construct(
		public int $perk_level_id,
		public int $strain_id,
		public int $amount,
	) {
	}
}
