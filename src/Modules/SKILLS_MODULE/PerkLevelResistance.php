<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class PerkLevelResistance extends DBRow {
	public int $perk_level_id;
	public int $strain_id;
	/** @db:ignore */
	public ?string $nanoline;
	public int $amount;
}
