<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\DBRow;
use Safe\DateTime;

class ApiKey extends DBRow {
	public DateTime $created;

	public function __construct(
		public string $character,
		public string $token,
		public string $pubkey,
		public ?int $id=null,
		public int $last_sequence_nr=0,
		?DateTime $created=null,
	) {
		$this->created = $created ?? new DateTime();
	}
}
