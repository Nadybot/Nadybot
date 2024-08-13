<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class ImplantInfo extends DBRow {
	public int $ability = 0;
	public int $treatment = 0;

	public function __construct(
		public int $ability_ql1,
		public int $ability_ql200,
		public int $ability_ql201,
		public int $ability_ql300,
		public int $treat_ql1,
		public int $treat_ql200,
		public int $treat_ql201,
		public int $treat_ql300,
		public int $shiny_effect_type_id,
		public int $bright_effect_type_id,
		public int $faded_effect_type_id,
		public string $ability_name,
	) {
	}
}
