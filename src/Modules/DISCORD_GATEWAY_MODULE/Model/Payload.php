<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use InvalidArgumentException;
use Nadybot\Core\JSONDataModel;

class Payload extends JSONDataModel {
	/** Opcode for the payload */
	public int $op;
	/** event data */
	public mixed $d = null;
	/** sequence number, used for resuming sessions and heartbeats */
	public ?int $s = null;
	/** the event name for this payload */
	public ?string $t = null;

	public function fromJSON(object $data): void {
		if (!isset($data->op)) {
			throw new InvalidArgumentException("Received non-payload data from Discord");
		}
		$this->op = $data->op;
		$this->d  = isset($data->d) ? $data->d : null;
		$this->s  = isset($data->s) ? $data->s : null;
		$this->t  = isset($data->t) ? $data->t : null;
	}
}
