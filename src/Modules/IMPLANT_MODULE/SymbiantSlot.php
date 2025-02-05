<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class SymbiantSlot {
	/**
	 * @param SkillAmount[] $reqs
	 * @param SkillAmount[] $mods
	 *
	 * @psalm-param list<SkillAmount> $reqs
	 * @psalm-param list<SkillAmount> $mods
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
