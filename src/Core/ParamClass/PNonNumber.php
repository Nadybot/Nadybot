<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PNonNumber extends Base {
	protected static string $regExp = ".*[^\d].*";
	protected string $value;

	public function __construct(string $value) {
		$this->value = $value;
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
