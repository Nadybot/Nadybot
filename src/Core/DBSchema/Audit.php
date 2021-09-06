<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use DateTime;
use Nadybot\Core\DBRow;

class Audit extends DBRow {
	public int $id;
	public string $actor;
	public string $actee;
	public string $action;
	public string $value;
	public DateTime $time;

	public function __construct() {
		$this->time = new DateTime();
	}
}
