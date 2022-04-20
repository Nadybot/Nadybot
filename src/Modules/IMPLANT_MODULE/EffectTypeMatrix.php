<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class EffectTypeMatrix extends DBRow {
	public int $ID;
	public string $Name;
	public int $MinValLow;
	public int $MaxValLow;
	public int $MinValHigh;
	public int $MaxValHigh;
}
