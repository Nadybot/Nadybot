<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\DBRow;

class Link extends DBRow {
	public int $id;
	public string $name;
	public string $website;
	public string $comments;
	public int $dt;
}
