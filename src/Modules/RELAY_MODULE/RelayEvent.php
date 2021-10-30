<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;

class RelayEvent extends DBRow {
	/**
	 * The id of the relay event. Lower id means higher priority
	 * @json:ignore
	 */
	public int $id;

	/**
	 * The id of the relay where this layer belongs to
	 * @json:ignore
	 */
	public int $relay_id;

	/** Which event is this for? */
	public string $event;

	/** Allow sending the event via this relay? */
	public bool $incoming = false;

	/** Allow receiving the event via this relay? */
	public bool $outgoing = false;

	public function toString(): string {
		return "{$this->event} ".
			($this->incoming ? "I" : "").
			($this->outgoing ? "O" : "");
	}
}
