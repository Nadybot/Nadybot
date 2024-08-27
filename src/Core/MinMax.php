<?php declare(strict_types=1);

namespace Nadybot\Core;

class MinMax {
	public function __construct(
		public int $min,
		public int $max
	) {
	}
}
