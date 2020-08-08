<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Alt extends DBRow {
	public string $alt;
	public ?string $main = null;
	public ?bool $validated = false;
}
