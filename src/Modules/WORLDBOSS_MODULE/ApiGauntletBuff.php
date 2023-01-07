<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

class ApiGauntletBuff {
	public function __construct(
		public string $faction,
		public int $expires,
		public int $dimension,
	) {
	}
}
