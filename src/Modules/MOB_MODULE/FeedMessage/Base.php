<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\FeedMessage;

use Nadybot\Core\Types\Dimension;
use Nadybot\Modules\MOB_MODULE\Mob;

class Base {
	public const string CORPSE = 'corpse';
	public const string DEATH = 'death';
	public const string SPAWN = 'spawn';
	public const string HP = 'hp';
	public const string OOR = 'out_of_range';

	/** The dimension this event is for */
	public int $dimension;

	public function __construct(
		public string $type,
		public string $event,
		public string $key,
		?int $dimension=null,
	) {
		$this->dimension = $dimension ?? Dimension::RK5->value;
	}

	public function processUpdate(Mob $mob): Mob {
		return clone $mob;
	}
}
