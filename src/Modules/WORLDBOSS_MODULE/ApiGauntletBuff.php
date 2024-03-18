<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\StringableTrait;
use Stringable;

class ApiGauntletBuff implements Stringable {
	use StringableTrait;

	public function __construct(
		public string $faction,
		public int $expires,
		public int $dimension,
	) {
	}
}
