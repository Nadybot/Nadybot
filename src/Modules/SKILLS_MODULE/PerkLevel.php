<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class PerkLevel extends DBRow {
	public int $id;
	public int $perk_id;
	public int $number;
	public int $min_level;
	/** @var string[] */
	public array $professions = [];
	/** @var array<string,int> */
	public array $buffs = [];
}
