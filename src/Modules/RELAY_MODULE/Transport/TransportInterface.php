<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Nadybot\Modules\RELAY_MODULE\RelayStackMemberInterface;

interface TransportInterface extends RelayStackMemberInterface {
	/**
	 * Send data over the transport
	 */
	public function send(string $data): bool;
}
