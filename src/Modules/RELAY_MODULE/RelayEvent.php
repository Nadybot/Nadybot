<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes\JSON;

class RelayEvent extends DBRow {
	/**
	 * The id of the relay event. Lower id means higher priority
	 */
	#[JSON\Ignore]
	public int $id;

	/**
	 * The id of the relay where this layer belongs to
	 */
	#[JSON\Ignore]
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
