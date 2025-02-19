<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents a combined minimum and maximum value */
class MinMax {
	public function __construct(
		public int $min,
		public int $max
	) {
	}
}
