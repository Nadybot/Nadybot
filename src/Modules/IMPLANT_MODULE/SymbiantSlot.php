<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class SymbiantSlot {
	/**
	 * @param AbilityAmount[] $reqs
	 * @param AbilityAmount[] $mods
	 *
	 * @psalm-param list<AbilityAmount> $reqs
	 * @psalm-param list<AbilityAmount> $mods
	 */
	public function __construct(
		public string $name,
		public int $treatment,
		public int $level,
		public array $reqs,
		public array $mods,
	) {
	}
}
