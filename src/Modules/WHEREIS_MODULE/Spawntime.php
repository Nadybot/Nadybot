<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\DBRow;

class Spawntime extends DBRow {
	/** @var Collection<WhereisResult> */
	public Collection $coordinates;

	/** @param ?Collection<WhereisResult> $coordinates */
	public function __construct(
		public string $mob,
		public ?string $alias=null,
		public ?string $placeholder=null,
		public ?bool $can_skip_spawn=null,
		public ?int $spawntime=null,
		?Collection $coordinates=null,
	) {
		$this->coordinates = $coordinates ?? new Collection();
	}
}
