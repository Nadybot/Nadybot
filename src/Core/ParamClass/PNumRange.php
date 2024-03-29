<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PNumRange extends Base {
	/** The smaller value */
	public int $low;

	/** The bigger value */
	public int $high;
	protected static string $regExp = "\d+\s*-\s*\d+";
	protected string $value;

	public function __construct(string $value) {
		[$low, $high] = \Safe\preg_split("/\s*-\s*/", $value);
		$this->low = min((int)$low, (int)$high);
		$this->high = max((int)$low, (int)$high);
		$this->value = $value;
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
