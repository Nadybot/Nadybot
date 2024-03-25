<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Symbiant extends DBRow {
	public function __construct(
		public int $ID,
		public string $Name,
		public int $QL,
		public int $SlotID,
		public int $TreatmentReq,
		public int $LevelReq,
		public string $Unit,
		#[NCA\DB\Ignore] public string $SlotName,
		#[NCA\DB\Ignore] public string $SlotLongName,
	) {
	}
}
