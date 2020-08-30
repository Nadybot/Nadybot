<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class PremadeSearchResult extends DBRow {
	public string $slot;
	public string $profession;
	public string $ability;
	public string $shiny;
	public string $bright;
	public string $fade;
}
