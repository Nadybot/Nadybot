<?php declare(strict_types=1);

namespace Nadybot\Modules\REPUTATION_MODULE;

use Nadybot\Core\DBRow;

class Reputation extends DBRow {
	public int $id;
	public string $name;
	public string $reputation;
	public string $comment;
	public string $by;
	public int $dt;
}
