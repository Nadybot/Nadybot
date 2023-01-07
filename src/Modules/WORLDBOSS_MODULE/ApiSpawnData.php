<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

class ApiSpawnData {
	public function __construct(
		public string $name,
		public int $last_spawn,
		public int $dimension,
	) {
	}
}
