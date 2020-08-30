<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class ImplantRequirements extends DBRow {
	public int $ql;
	public int $treatment;
	public int $ability;
	public int $abilityShiny;
	public int $abilityBright;
	public int $abilityFaded;
	public int $skillShiny;
	public int $skillBright;
	public int $skillFaded;
}
