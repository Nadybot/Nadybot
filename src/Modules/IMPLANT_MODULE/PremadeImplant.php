<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, MapRead, Shared, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\{Ability, Profession};

#[Table(name: 'premade_implant', shared: Shared::Yes)]
class PremadeImplant extends DBTable {
	public function __construct(
		public int $implant_type_id,
		#[
			MapRead([Profession::class, 'fromNumber']),
			ColName('profession_id'),
		] public Profession $profession,
		#[
			MapRead([Ability::class, 'fromID']),
			ColName('ability_id'),
		] public Ability $ability,
		public int $shiny_cluster_id,
		public int $bright_cluster_id,
		public int $faded_cluster_id,
	) {
	}
}
