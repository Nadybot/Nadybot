<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class Symbiant extends DBRow {
	public int $ID;
	public string $Name;
	public int $QL;
	public int $SlotID;
	/** @db:ignore */
	public string $SlotName;
	/** @db:ignore */
	public string $SlotLongName;
	public int $TreatmentReq;
	public int $LevelReq;
	public string $Unit;
}
