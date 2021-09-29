<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

class PCharacterList extends Base {
	protected static string $regExp = "(?:[a-zA-Z][a-zA-Z0-9-]{3,11}\s+)*[a-zA-Z][a-zA-Z0-9-]{3,11}";
	protected string $value;
	public array $chars = [];

	public function __construct(string $value) {
		$this->chars = preg_split("/\s+/", $value);
		$this->chars = array_map("strtolower", $this->chars);
		$this->chars = array_map("ucfirst", $this->chars);
		$this->value = join(", ", $this->chars);
	}

	/** @return string[] */
	public function __invoke(): array {
		return $this->chars;
	}

	public function __toString(): string {
		return $this->value;
	}
}
