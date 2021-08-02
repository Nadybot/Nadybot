<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

interface TransportInterface {
	/**
	 * Send data over the transport
	 */
	public function send(string $data): bool;

	/**
	 * Initialize the protocol and call the $callback when done
	 */
	public function init(?object $previous, callable $callback): void;
}
