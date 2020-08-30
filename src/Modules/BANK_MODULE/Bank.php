<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Nadybot\Core\DBRow;

class Bank extends DBRow {
	public ?string $name;
	public ?int $lowid;
	public ?int $highid;
	public ?int $ql;
	public ?string $player;
	public ?string $container;
	public ?int $container_id;
	public ?string $location;
}
