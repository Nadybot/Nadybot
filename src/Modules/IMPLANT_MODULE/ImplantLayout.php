<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class ImplantLayout extends DBRow {
	public int $AbilityQL1;
	public int $AbilityQL200;
	public int $AbilityQL201;
	public int $AbilityQL300;

	public int $TreatQL1;
	public int $TreatQL200;
	public int $TreatQL201;
	public int $TreatQL300;

	public string $ShinyEffect;
	public string $BrightEffect;
	public string $FadedEffect;
}
