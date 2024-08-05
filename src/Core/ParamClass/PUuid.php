<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PUuid extends Base {
	protected static string $regExp = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
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
