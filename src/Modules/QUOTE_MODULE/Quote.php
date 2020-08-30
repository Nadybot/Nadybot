<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use Nadybot\Core\DBRow;

class Quote extends DBRow {
	public int $id;
	public string $poster;
	public int $dt;
	public string $msg;
}
