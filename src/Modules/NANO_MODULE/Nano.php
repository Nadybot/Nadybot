<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\DBRow;

class Nano extends DBRow {
	public int $crystal_id;
	public int $nano_id;
	public int $ql;
	public string $crystal_name;
	public string $nano_name;
	public string $school;
	public string $strain;
	public int $strain_id;
	public string $sub_strain;
	public string $professions;
	public string $location;
	public int $nano_cost;
	public bool $froob_friendly;
}
