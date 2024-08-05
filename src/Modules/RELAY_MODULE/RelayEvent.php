<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'relay_event')]
class RelayEvent extends DBTable {
	/** The id of the relay event. Lower id means higher priority */

	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param UuidInterface  $relay_id The id of the relay where this layer belongs to
	 * @param string         $event    Which event is this for?
	 * @param ?UuidInterface $id       The id of the relay event. Lower id means higher priority
	 * @param bool           $incoming Allow sending the event via this relay?
	 * @param bool           $outgoing Allow receiving the event via this relay?
	 */
	public function __construct(
		#[NCA\JSON\Ignore] public UuidInterface $relay_id,
		public string $event,
		?UuidInterface $id=null,
		public bool $incoming=false,
		public bool $outgoing=false,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}

	public function toString(): string {
		return "{$this->event} ".
			($this->incoming ? 'I' : '').
			($this->outgoing ? 'O' : '');
	}
}
