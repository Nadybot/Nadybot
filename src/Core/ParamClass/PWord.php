<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PWord extends Base {
	protected static string $regExp = '[^ ]+';

	/** @psalm-var non-empty-string */
	protected string $value;

	/** @psalm-param non-empty-string $value */
	public function __construct(string $value) {
		$this->value = $value;
	}

	/** @psalm-return non-empty-string */
	public function __invoke(): string {
		return $this->value;
	}

	/** @psalm-return non-empty-string */
	public function __toString(): string {
		return $this->value;
	}
}
