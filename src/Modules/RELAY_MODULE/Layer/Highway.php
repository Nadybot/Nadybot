<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;

/**
 * @RelayStackMember("highway")
 * @Description("This is the highway protocol, spoken by the highway websocket-server")
 * @Param(name='room', description='The room to join', type='string', required=true)
 */
class Highway implements RelayLayerInterface {
	protected string $room;

	protected Relay $relay;

	/** @Logger */
	public LoggerWrapper $logger;

	protected $initCallback = null;

	public function __construct(string $room) {
		$this->room = $room;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function init(callable $callback): array {
		$json = (object)[
			"type" => "command",
			"cmd" => "subscribe",
			"room" => $this->room,
		];
		try {
			$encoded = json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				"Unable to encode subscribe-command into highway protocol: ".
					$e->getMessage()
			);
			return [];
		}
		$this->initCallback = $callback;
		return [$encoded];
	}

	public function deinit(callable $callback): array {
		$json = (object)[
			"type" => "command",
			"cmd" => "unsubscribe",
			"room" => $this->room,
		];
		try {
			$encoded = json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				"Unable to encode unsubscribe-command into highway protocol: ".
					$e->getMessage()
			);
			return [];
		}
		$this->initCallback = $callback;
		return [$encoded];
	}

	public function send(array $packets): array {
		$encoded = [];
		foreach ($packets as $packet) {
			$json = (object)[
				"type" => "message",
				"room" => $this->room,
				"body" => $packet,
			];
			try {
				$encoded []= json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (JsonException $e) {
				$this->logger->log(
					'ERROR',
					"Unable to encode the relay data into highway protocol: ".
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
			$this->logger->log(
				'ERROR',
				"Unable to decode highway message: ".
					$e->getMessage()
			);
			return null;
		}
		if (!isset($json->type)) {
			$this->logger->log('ERROR', 'Received highway message without type');
			return null;
		}
		if ($json->type === "success" && isset($this->initCallback)) {
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
			return null;
		}
		if ($json->type !== "message") {
			return null;
		}
		if (!isset($json->body)) {
			$this->logger->log('ERROR', 'Received highway message without body');
			return null;
		}
		return $json->body;
	}
}
