<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Types\Skill;

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
		public ?Skill $shiny_effect,
		public ?Skill $bright_effect,
		public ?Skill $faded_effect,
	) {
	}
}
