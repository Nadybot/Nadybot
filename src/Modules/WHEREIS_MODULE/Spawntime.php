<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\DBRow;

class Spawntime extends DBRow {
	public string $mob;
	public ?string $alias = null;
	public ?string $placeholder = null;
	public ?bool $can_skip_spawn = null;
	public ?int $spawntime = null;

	/** @var Collection<WhereisResult> */
	public Collection $coordinates;
}
