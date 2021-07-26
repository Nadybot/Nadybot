<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

interface TransportInterface {
	/**
	 * Send data over the transport
	 */
	public function send(string $data): bool;

	/**
	 * Initialize the protocol and return if success or not
	 */
	public function init(): bool;
}
