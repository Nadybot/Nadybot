<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface RelayStackMember {
	/**
	 * Send one or more packets to the transport
	 * @param string[] $data
	 * @return string[]
	 */
	public function send(array $data): array;

	/**
	 * Receive a packet and process it
	 */
	public function receive(string $packet): ?string;

	/**
	 * Initialize the protocol and return if success or not
	 */
	public function init(): bool;
}
