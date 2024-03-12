<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill\Packet;

use Amp\Websocket\Client\WebsocketConnection;
use Nadybot\Core\{Attributes as NCA};
use Psr\Log\LoggerInterface;

abstract class Base {
	#[NCA\Logger]
	protected LoggerInterface $logger;

	public function send(WebsocketConnection $connection): void {
		$message = $this->toString();
		$this->logger->debug("Sending data to Drill-server: {data}", [
			"data" => $this->dumpPackage(),
		]);
		$connection->sendBinary($message);
	}

	abstract public static function fromString(string $message): self;

	abstract public function toString(): string;

	abstract public function getType(): int;

	private function dumpPackage(): string {
		$data = ['type' => $this->getType() . " (" . class_basename($this) . ")"];
		$data = array_merge($data, get_object_vars($this));
		unset($data["logger"]);
		array_walk(
			$data,
			function (string|int &$value, string $key): void {
				$value = "{$key}={$value}";
			},
		);
		return join(", ", $data);
	}
}
