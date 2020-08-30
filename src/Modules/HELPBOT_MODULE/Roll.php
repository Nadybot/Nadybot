<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\DBRow;

class Roll extends DBRow {
	public int $id;
	public ?int $time;
	public ?string $name;
	public ?string $options;
	public ?string $result;
}
