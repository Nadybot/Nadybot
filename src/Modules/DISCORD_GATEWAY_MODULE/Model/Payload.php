<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class Payload extends JSONDataModel {
	/** Opcode for the payload */
	public int $op;
	/** event data */
	public mixed $d;
	/** sequence number, used for resuming sessions and heartbeats */
	public ?int $s;
	/** the event name for this payload */
	public ?string $t;

	public function fromJSON(object $data): void {
		if (!isset($data->op) || !isset($data->d)) {
			return;
		}
		$this->op = $data->op;
		$this->d  = $data->d;
		$this->s  = isset($data->s) ? $data->s : null;
		$this->t  = isset($data->t) ? $data->t : null;
	}
}
