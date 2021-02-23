<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class PerkBuffSearchResult extends DBRow {
	public ?string $name;
	public int $amount;
	public int $perk_level;
	public string $profs;
	public string $unit;
	public string $expansion;
}
