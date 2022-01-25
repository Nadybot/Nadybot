<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Str {
	/** @var string[] */
	public array $values = [];
	public function __construct(string $value, string ...$values) {
		$this->values = array_unique(array_merge([$value], $values));
	}
}
