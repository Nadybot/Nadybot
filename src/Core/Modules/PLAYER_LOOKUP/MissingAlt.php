<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

class MissingAlt {
	public function __construct(
		public string $name,
		public int $dimension,
	) {
	}
}
