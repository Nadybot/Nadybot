<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents a combined minimum and maximum value */
class MinMax {
	/**
	 * @param int $min The minimum value
	 * @param int $max The maximum value
	 */
	public function __construct(
		public int $min,
		public int $max
	) {
	}
}
