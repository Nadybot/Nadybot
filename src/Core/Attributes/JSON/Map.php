<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\JSON;

use Attribute;
use Closure;

/** Map the value before exporting to JSON via JsonExporter */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Map {
	public Closure $mapper;

	public function __construct(callable $mapper) {
		$this->mapper = Closure::fromCallable($mapper);
	}
}
