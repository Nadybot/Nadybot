<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\ParamClass\Base;

class PClusterSlot extends Base {
	protected static string $regExp = "shiny|bright|faded|symbiant|symb";
	protected string $value;

	public function __construct(string $value) {
		$this->value = strtolower($value);
		if ($this->value === "symbiant") {
			$this->value = "symb";
		}
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
