<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface RelayStackMemberInterface {
	/**
	 * Initialize the layer and call the $callback when done
	 *
	 * @return list<string> The data to bubble down the stack during init
	 */
	public function init(callable $callback): array;

	/**
	 * Bring down the layer and call the $callback when done
	 *
	 * @return list<string> The data to bubble down the stack during de-init
	 */
	public function deinit(callable $callback): array;

	/** Set the relay for this layer */
	public function setRelay(Relay $relay): void;
}
