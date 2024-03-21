<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class RelayEvent extends DBRow {
	/**
	 * @param int    $relay_id The id of the relay where this layer belongs to
	 * @param string $event    Which event is this for?
	 * @param ?int   $id       The id of the relay event. Lower id means higher priority
	 * @param bool   $incoming Allow sending the event via this relay?
	 * @param bool   $outgoing Allow receiving the event via this relay?
	 */
	public function __construct(
		#[NCA\JSON\Ignore]
		public int $relay_id,
		public string $event,
		#[NCA\JSON\Ignore]
		#[NCA\DB\AutoInc]
		public ?int $id=null,
		public bool $incoming=false,
		public bool $outgoing=false,
	) {
	}

	public function toString(): string {
		return "{$this->event} ".
			($this->incoming ? "I" : "").
			($this->outgoing ? "O" : "");
	}
}
