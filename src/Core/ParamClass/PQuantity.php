<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PQuantity extends Base {
	protected static string $regExp = "\d+[x*]?";
	protected int $value;

	public function __construct(string $value) {
		$this->value = (int)$value;
	}

	public function __invoke(): int {
		return $this->value;
	}

	public function __toString(): string {
		return (string)$this->value;
	}
}
