<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use DateTime;
use Nadybot\Core\DBRow;

class ApiKey extends DBRow {
	public int $id;
	public string $character;
	public string $token;
	public int $last_sequence_nr = 0;
	public string $pubkey;
	public DateTime $created;

	public function __construct() {
		$this->created = new DateTime();
	}
}
