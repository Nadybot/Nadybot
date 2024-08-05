<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'perk', shared: NCA\DB\Shared::Yes)]
class Perk extends DBTable {
	/** Internal ID of that perkline */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string               $name        Name of the perk
	 * @param string               $expansion   The expansion needed for this perk
	 * @param ?string              $description A description what a perk does
	 * @param ?UuidInterface       $id          Internal ID of that perkline
	 * @param array<int,PerkLevel> $levels
	 */
	public function __construct(
		public string $name,
		public string $expansion='sl',
		public ?string $description=null,
		?UuidInterface $id=null,
		#[NCA\DB\Ignore] public array $levels=[],
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
