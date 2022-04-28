<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\DBRow;

class OrgNote extends DBRow {
	public ?int $id = null;
	public string $uuid;
	public string $added_by;
	public int $added_on;
	public string $note;

	public function __construct() {
		$this->added_on = time();
	}
}
