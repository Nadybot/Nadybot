<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Socket\AsyncSocket;

/**
 * A factory to Nadybot\Core\Socket\AsyncSocket
 * @Instance
 */
class Socket {
	/**
	 * Wrap a socket resource into a class for easy async operations
	 * @param resource $socket
	 */
	public function wrap($socket): AsyncSocket {
		$asyncSocket = new AsyncSocket($socket);
		Registry::injectDependencies($asyncSocket);
		return  $asyncSocket;
	}
}
