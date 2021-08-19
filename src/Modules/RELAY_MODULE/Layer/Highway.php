<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Exception;
use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\StatusProvider;

/**
 * @RelayStackMember("highway")
 * @Description("This is the highway protocol, spoken by the highway websocket-server.
 * 	It will broadcast incoming messages to all clients in the same room.
 * 	Room names can be picked freely as long as they are at least 32 characters
 * 	long. They should be as random as possible to prevent unauthorized
 *	access to messages.
 *	For further security, using an encryption layer is recommended.")
 * @Param(
 * 	name='room',
 * 	description='The room to join. Must be at least 32 characters long.',
 * 	type='string',
 * 	required=true
 * )
 */
class Highway implements RelayLayerInterface, StatusProvider {
	protected string $room;

	protected Relay $relay;

	protected ?string $status;

	/** @Logger */
	public LoggerWrapper $logger;

	protected $initCallback = null;

	public function __construct(string $room) {
		if (strlen($room) < 32) {
			throw new Exception("<highlight>room<end> must be at least 32 characters long.");
		}
		$this->room = $room;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): string {
		return $this->status ?? "unknown";
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
			$this->status = "Unable to encode subscribe-command into ".
				"highway protocol: " . $e->getMessage();
			$this->logger->log('ERROR', $this->status);
			return [];
		}
		$this->initCallback = $callback;
		$this->status = "Joining room {$this->room}";
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
			$this->status = "Unable to encode unsubscribe-command into ".
				"highway protocol: " . $e->getMessage();
			$this->logger->log('ERROR', $this->status);
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
			$this->status = "Unable to decode highway message: ".
					$e->getMessage();
			$this->logger->log('ERROR', $this->status);
			return null;
		}
		if (!isset($json->type)) {
			$this->status = 'Received highway message without type';
			$this->logger->log('ERROR', $this->status);
			return null;
		}
		if ($json->type === "success" && isset($this->initCallback)) {
			$this->status = "ready";
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
			return null;
		}
		if ($json->type === "error") {
			$this->logger->log("ERROR", $json->message);
			$this->status = $json->message;
			return null;
		}
		if ($json->type !== "message") {
			return null;
		}
		if (!isset($json->body)) {
			$this->status = 'Received highway message without body';
			$this->logger->log('ERROR', $this->status);
			return null;
		}
		return $json->body;
	}
}
