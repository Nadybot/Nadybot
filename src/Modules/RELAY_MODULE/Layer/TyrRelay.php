<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayStatus;
use Nadybot\Modules\RELAY_MODULE\StatusProvider;

/**
 * @RelayStackMember("tyr-relay")
 * @Description("This is the protocol spoken by Tyrence's websocket-server")
 */
class TyrRelay implements RelayLayerInterface, StatusProvider {
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @Logger */
	public LoggerWrapper $logger;

	protected $initCallback = null;

	public function __construct() {
	}

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

	public function send(array $packets): array {
		$encoded = [];
		foreach ($packets as $packet) {
			$json = (object)[
				"type" => "message",
				"payload" => $packet,
			];
			try {
				$encoded []= json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (JsonException $e) {
				$this->logger->log(
					'ERROR',
					"Unable to encode the relay data into tyr-relay protocol: ".
						$e->getMessage()
				);
				continue;
			}
		}
		return $encoded;
	}

	public function receive(string $data): ?string {
		try {
			$json = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				"Unable to decode tyr-relay message: " . $e->getMessage()
			);
			$this->logger->log('ERROR', $this->status->text);
			return null;
		}
		if (!isset($json->type)) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Received tyr-relay message without type'
			);
			$this->logger->log('ERROR', $this->status->text);
			return null;
		}
		if ($json->type !== "message") {
			return null;
		}
		if (!isset($json->payload)) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				'Received tyr-relay message without payload'
			);
			$this->logger->log('ERROR', $this->status->text);
			return null;
		}
		return $json->payload;
	}
}
