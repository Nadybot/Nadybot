<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class PerkLevelResistance extends DBRow {
	public int $perk_level_id;
	#[NCA\DB\Ignore]
	public int $perk_level;
	public int $strain_id;
	#[NCA\DB\Ignore]
	public ?string $nanoline;
	public int $amount;
}
