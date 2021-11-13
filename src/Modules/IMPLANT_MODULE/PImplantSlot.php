<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\ParamClass\Base;

class PImplantSlot extends Base {
	protected static string $regExp = "head|eye|ear|rarm|chest|larm|rwrist|waist|lwrist|rhand|legs|lhand|feet";
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtolower($value);
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
