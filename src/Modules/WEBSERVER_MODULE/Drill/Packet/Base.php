<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use function Amp\call;
use Amp\Promise;
use Amp\Websocket\Client\Connection;

use Generator;

abstract class Base {
	/** @return Promise<void> */
	public function send(Connection $connection): Promise {
		return call(function () use ($connection): Generator {
			$message = $this->toString();
			yield $connection->sendBinary($message);
		});
	}

	abstract public static function fromString(string $message): self;

	abstract public function toString(): string;

	abstract public function getType(): int;
}
