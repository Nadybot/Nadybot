<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\DBRow;

class Note extends DBRow {
	public int $id;
	public string $owner;
	public string $added_by;
	public string $note;
	public int $dt;
}
