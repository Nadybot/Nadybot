<?php declare(strict_types=1);

namespace Nadybot\Modules\BROADCAST_MODULE;

use Nadybot\Core\DBRow;

class Broadcast extends DBRow {
	public ?string $name;
	public ?string $added_by;
	public ?int $dt;
}
