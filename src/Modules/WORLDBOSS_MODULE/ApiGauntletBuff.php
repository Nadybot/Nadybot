<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	StringableTrait,
	Types\Faction
};
use Stringable;

class ApiGauntletBuff implements Stringable {
	use StringableTrait;

	public function __construct(
		#[
			NCA\Hydrator\StrFuncIn('strtolower', 'ucfirst')
		] public Faction $faction,
		public int $expires,
		public int $dimension,
	) {
	}
}
