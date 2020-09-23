<?php declare(strict_types=1);

namespace Nadybot\Core\Socket;

class TlsClientStart implements WriteClosureInterface {
	public function exec(AsyncSocket $socket): ?bool {
		$result = stream_socket_enable_crypto($socket->getSocket(), true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		if (is_bool($result)) {
			return $result;
		}
		return null;
	}
	
	public function allowReading(): bool {
		return false;
	}
}
