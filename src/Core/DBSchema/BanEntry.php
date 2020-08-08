<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class BanEntry extends DBRow {
	public int $charid;
	public ?string $admin;
	public ?int $time;
	public ?string $reason;
	public ?int $banend;
}
