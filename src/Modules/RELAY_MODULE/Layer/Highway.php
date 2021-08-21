<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Exception;
use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayStatus;
use Nadybot\Modules\RELAY_MODULE\StatusProvider;

/**
 * @RelayStackMember("highway")
 * @Description("This is the highway protocol, spoken by the highway websocket-server.
 * 	It will broadcast incoming messages to all clients in the same room.
 * 	Room names can be picked freely as long as they are at least 32 characters
 * 	long. They should be as random as possible to prevent unauthorized
 *	access to messages.
 *	Shorter room names are system rooms and by definition read-only.
 *	For further security, using an encryption layer is recommended.")
 * @Param(
 * 	name='room',
 * 	description='The room(s) to join. Must be at least 32 characters long if you want to be able to send.',
 * 	type='string[]',
 * 	required=true
 * )
 */
class Highway implements RelayLayerInterface, StatusProvider {
	/** @var string[] */
	protected array $rooms = [];

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @Logger */
	public LoggerWrapper $logger;

	protected $initCallback = null;

	public function __construct(array $rooms) {
		foreach ($rooms as $room) {
			if (strlen($room) < 32) {
				throw new Exception("<highlight>room<end> must be at least 32 characters long.");
			}
		}
		$this->rooms = $rooms;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function init(callable $callback): array {
		$cmd = [];
		foreach ($this->rooms as $room) {
			$json = (object)[
				"type" => "command",
				"cmd" => "subscribe",
				"room" => $room,
			];
			try {
				$encoded = json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (JsonException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Unable to encode subscribe-command into highway protocol: ".
						$e->getMessage()
				);
				$this->logger->log('ERROR', $this->status->text);
				return [];
			}
			$this->initCallback = $callback;
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				"Joining room {$room}"
			);
			$cmd []= $encoded;
		}
		return $cmd;
	}

	public function deinit(callable $callback): array {
		$cmd = [];
		foreach ($this->rooms as $room) {
			$json = (object)[
				"type" => "command",
				"cmd" => "unsubscribe",
				"room" => $room,
			];
			try {
				$encoded = json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (JsonException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Unable to encode unsubscribe-command into highway protocol: ".
						$e->getMessage()
				);
				$this->logger->log('ERROR', $this->status->text);
				return [];
			}
			$this->initCallback = $callback;
			$cmd []= $encoded;
		}
		return $cmd;
	}

	public function send(array $packets): array {
		$encoded = [];
		foreach ($packets as $packet) {
			foreach ($this->rooms as $room) {
				$json = (object)[
					"type" => "message",
					"room" => $room,
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
		}
		return $encoded;
	}

	public function receive(string $data): ?string {
		try {
			$json = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->status = new RelayStatus(
				RelayStatus::ERROR,
				"Unable to decode highway message: " . $e->getMessage()
			);
			$this->logger->log('ERROR', $this->status->text);
			return null;
		}
		if (!isset($json->type)) {
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				'Received highway message without type'
			);
			$this->logger->log('WARN', $this->status->text);
			return null;
		}
		if ($json->type === "success" && isset($this->initCallback)) {
			$this->status = new RelayStatus(RelayStatus::READY, "ready");
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
			return null;
		}
		if ($json->type === "error") {
			$this->logger->log("ERROR", $json->message);
			$this->status = new RelayStatus(RelayStatus::ERROR, $json->message);
			return null;
		}
		if ($json->type !== "message") {
			return null;
		}
		if (!isset($json->body)) {
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				'Received highway message without body'
			);
			$this->logger->log('ERROR', $this->status->text);
			return null;
		}
		return $json->body;
	}
}
