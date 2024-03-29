<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\{Attributes as NCA, LoggerWrapper};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayLayerInterface,
	RelayMessage,
	RelayStatus,
	StatusProvider,
};
use Safe\Exceptions\JsonException;

#[
	NCA\RelayStackMember(
		name: "tyr-relay",
		description: "This is the protocol spoken by Tyrence's websocket-server"
	)
]
class TyrRelay implements RelayLayerInterface, StatusProvider {
	#[NCA\Logger]
	public LoggerWrapper $logger;
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @var ?callable */
	protected $initCallback = null;

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function send(array $data): array {
		$encoded = [];
		foreach ($data as $packet) {
			$json = (object)[
				"type" => "message",
				"payload" => $packet,
			];
			try {
				$encoded []= \Safe\json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (JsonException $e) {
				$this->logger->error(
					"Unable to encode the relay data into tyr-relay protocol: ".
						$e->getMessage(),
					["exception" => $e]
				);
				continue;
			}
		}
		return $encoded;
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$data) {
			try {
				$json = \Safe\json_decode($data);
			} catch (JsonException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Unable to decode tyr-relay message: " . $e->getMessage()
				);
				$this->logger->error($this->status->text);
				$data = null;
				continue;
			}
			if (isset($json->client_id)) {
				$msg->sender = $json->client_id;
			}
			if (!isset($json->type)) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'Received tyr-relay message without type'
				);
				$this->logger->error($this->status->text);
				$data = null;
				continue;
			}
			if ($json->type === "left") {
				if (isset($msg->sender)) {
					$this->relay->setClientOffline($msg->sender);
				}
				$data = null;
				continue;
			}
			if ($json->type !== "message") {
				$data = null;
				continue;
			}
			if (!isset($json->payload)) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					'Received tyr-relay message without payload'
				);
				$this->logger->error($this->status->text);
				$data = null;
				continue;
			}
			$data = $json->payload;
		}
		$msg->packages = array_values(array_filter($msg->packages));
		return $msg;
	}
}
