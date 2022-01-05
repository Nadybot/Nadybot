<?php declare(strict_types=1);

namespace Nadybot\Modules\DISC_MODULE;

use Nadybot\Core\DBRow;

class NanoDetails extends DBRow {
	public string $location;
	public string $professions;
	public string $nanoline_name;
}
