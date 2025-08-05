<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\DB\{Ignore, PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'spawntime', shared: Shared::Yes)]
class Spawntime extends DBTable {
	/** @var Collection<int,Whereis> */
	#[Ignore] public Collection $coordinates;

	/** @param ?Collection<int,Whereis> $coordinates */
	public function __construct(
		#[PK] public string $mob,
		public ?string $alias=null,
		public ?string $placeholder=null,
		public ?bool $can_skip_spawn=null,
		public ?int $spawntime=null,
		?Collection $coordinates=null,
	) {
		/** @var Collection<int,Whereis> */
		$empty = new Collection();
		$this->coordinates = $coordinates ?? $empty;
	}
}
