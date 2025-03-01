<?php declare(strict_types=1);

namespace Nadybot\Core\ParamClass;

use AO\Utils;
use Nadybot\Core\Safe;

/**
 * This is a normalized list of valid Anarchy Online character names.
 * Will return them as an array of strings.
 */
class PCharacterList extends Base {
	/** @var list<string> */
	public array $chars = [];
	protected static string $regExp = "(?:[a-zA-Z][a-zA-Z0-9-]{3,11}\s+)*[a-zA-Z][a-zA-Z0-9-]{3,11}";
	protected string $value;

	public function __construct(string $value) {
		$this->chars = Safe::pregSplit("/\s+/", $value);
		$this->chars = array_map(Utils::normalizeCharacter(...), $this->chars);
		$this->value = implode(', ', $this->chars);
	}

	/** @return list<string> */
	public function __invoke(): array {
		return $this->chars;
	}

	public function __toString(): string {
		return $this->value;
	}
}
