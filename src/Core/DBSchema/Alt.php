<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Alt extends DBRow {
	public string $alt;
	public string $main;
	public ?bool $validated_by_main = false;
	public ?bool $validated_by_alt = false;
	public ?string $added_via = null;
}
