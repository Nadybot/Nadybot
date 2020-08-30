<?php declare(strict_types=1);

namespace Nadybot\Modules\REPUTATION_MODULE;

use Nadybot\Core\DBRow;

class Kos extends DBRow {
	public string $name;
	public string $comment;
	public string $submitter;
	public int $dt;
}
