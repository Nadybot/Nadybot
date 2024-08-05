<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\Profession;
use Ramsey\Uuid\UuidInterface;

#[Table(name: 'perk_level_prof', shared: Shared::Yes)]
class PerkLevelProf extends DBTable {
	public function __construct(
		public UuidInterface $perk_level_id,
		public Profession $profession,
	) {
	}
}
