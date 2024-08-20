<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\DBRow;

class Death extends DBRow {
	public string $character;
	public int $counter = 0;
}
