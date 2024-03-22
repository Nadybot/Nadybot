<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Perk extends DBRow {
	/**
	 * @param string               $name        Name of the perk
	 * @param string               $expansion   The expansion needed for this perk
	 * @param ?string              $description A description what a perk does
	 * @param ?int                 $id          Internal ID of that perkline
	 * @param array<int,PerkLevel> $levels
	 */
	public function __construct(
		public string $name,
		public string $expansion='sl',
		public ?string $description=null,
		public ?int $id=null,
		#[NCA\DB\Ignore] public array $levels=[],
	) {
	}
}
