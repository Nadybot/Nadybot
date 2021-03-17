<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\DBRow;

class Note extends DBRow {
	public const REMIND_NONE = 0;
	public const REMIND_SELF = 1;
	public const REMIND_ALL = 2;

	public int $id;
	public string $owner;
	public string $added_by;
	public string $note;
	public int $dt;
	public int $reminder = self::REMIND_NONE;

	public function __construct() {
		$this->dt = time();
	}
}
