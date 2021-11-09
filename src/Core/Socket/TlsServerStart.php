<?php declare(strict_types=1);

namespace Nadybot\Core\Socket;

class TlsServerStart implements WriteClosureInterface {
	public function exec(AsyncSocket $socket): ?bool {
		$rawSocket = $socket->getSocket();
		if (!is_resource($rawSocket)) {
			return false;
		}
		$result = stream_socket_enable_crypto($rawSocket, true, STREAM_CRYPTO_METHOD_ANY_SERVER);
		if (is_bool($result)) {
			return $result;
		}
		return null;
	}

	public function allowReading(): bool {
		return false;
	}
}
