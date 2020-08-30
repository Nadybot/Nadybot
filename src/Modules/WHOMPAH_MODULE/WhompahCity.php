<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE;

use Nadybot\Core\DBRow;

class WhompahCity extends DBRow {
	public int $id;
	public string $city_name;
	public string $zone;
	public string $faction;
	public string $short_name;
	/** @var int[] */
	public array $connections = [];
	public bool $visited = false;
	public ?WhompahCity $previous = null;
}
