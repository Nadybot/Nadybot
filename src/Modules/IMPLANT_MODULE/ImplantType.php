<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class ImplantType extends DBRow {
	public function __construct(
		public int $ImplantTypeID,
		public string $Name,
		public string $ShortName,
	) {
	}
}
