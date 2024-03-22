<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PFaction extends Base {
	public string $lower;
	public string $color;
	protected static string $regExp = 'omni|clan|neutral|neut';
	protected string $value;

	public function __construct(string $value) {
		$this->value = ucfirst(strtolower($value));
		if ($this->value === 'Neut') {
			$this->value = 'Neutral';
		}
		$this->lower = strtolower($this->value);
		$this->color = "<{$this->lower}>";
	}

	public function __invoke(): string {
		return $this->value;
	}

	public function __toString(): string {
		return $this->value;
	}
}
