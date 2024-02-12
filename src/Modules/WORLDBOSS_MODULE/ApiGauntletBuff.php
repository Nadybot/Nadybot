<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\{Loggable,LoggableTrait};

class ApiGauntletBuff implements Loggable {
	use LoggableTrait;

	public function __construct(
		public string $faction,
		public int $expires,
		public int $dimension,
	) {
	}
}
