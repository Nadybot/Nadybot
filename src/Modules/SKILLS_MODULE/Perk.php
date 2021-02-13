<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class Perk extends DBRow {
	public int $id;
	public string $name;
	/** @var array<int,PerkLevel> */
	public array $levels = [];
}
