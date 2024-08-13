<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class ImplantLayout extends DBRow {
	public function __construct(
		public int $ability_ql1,
		public int $ability_ql200,
		public int $ability_ql201,
		public int $ability_ql300,
		public int $treat_ql1,
		public int $treat_ql200,
		public int $treat_ql201,
		public int $treat_ql300,
		public string $shiny_effect,
		public string $bright_effect,
		public string $faded_effect,
	) {
	}
}
