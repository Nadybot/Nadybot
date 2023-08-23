<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\DBRow;

class Fun extends DBRow {
	public int $id;
	public string $type;
	public string $content;
}
