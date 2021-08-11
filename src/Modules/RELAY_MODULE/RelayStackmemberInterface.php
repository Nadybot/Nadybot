<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface RelayStackMemberInterface {
	/**
	 * Initialize the layer and call the $callback when done
	 */
	public function init(object $previous, callable $callback): void;

	/**
	 * Bring down the layer and call the $callback when done
	 */
	public function deinit(object $previous, callable $callback): void;

	/**
	 * Set the relay for this layer
	 */
	public function setRelay(Relay $relay): void;
}
