<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\{DBRow, Faction, Government};

class Organization extends DBRow {
	public function __construct(
		public int $id,
		public string $name='Illegal Org',
		public int $num_members=0,
		public Faction $faction=Faction::Neutral,
		public Government $governing_form=Government::Anarchism,
		public string $index='others',
	) {
	}
}
