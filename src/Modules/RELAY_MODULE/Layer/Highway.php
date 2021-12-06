<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Exception;
use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
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
	public const TYPE_MESSAGE = "message";
	public const TYPE_JOIN = "join";
	public const TYPE_LEAVE = "leave";
	public const TYPE_ROOM_INFO = "room-info";
	public const TYPE_SUCCESS = "success";
	public const TYPE_ERROR = "error";
	public const TYPE_HELLO = "hello";

	/** @var string[] */
	protected array $rooms = [];

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var ?callable */
	protected $initCallback = null;

	/** @var ?callable */
	protected $deInitCallback = null;

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
				"type" => static::TYPE_JOIN,
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
				$this->logger->error($this->status->text);
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
				"type" => static::TYPE_LEAVE,
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
				$this->logger->error($this->status->text);
				return [];
			}
			$this->deInitCallback = $callback;
			$cmd []= $encoded;
		}
		return $cmd;
	}

	public function send(array $data): array {
		$encoded = [];
		foreach ($data as $packet) {
			foreach ($this->rooms as $room) {
				$json = (object)[
					"type" => static::TYPE_MESSAGE,
					"room" => $room,
					"body" => $packet,
				];
				try {
					$encoded []= json_encode($json, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
				} catch (JsonException $e) {
					$this->logger->error(
						"Unable to encode the relay data into highway protocol: ".
							$e->getMessage(),
						["exception" => $e]
					);
					continue;
				}
			}
		}
		return $encoded;
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$data) {
			try {
				$this->logger->debug("Received highway message on relay {relay}: {message}", [
					"relay" => $this->relay->getName(),
					"message" => $data,
				]);
				$json = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Unable to decode highway message: " . $e->getMessage()
				);
				$this->logger->error("Unable to decode highway message on {relay}: {message}", [
					"relay" => $this->relay->getName(),
					"message" => $e->getMessage(),
					"exception" => $e,
				]);
				$data = null;
				continue;
			}
			if (isset($json->user)) {
				$msg->sender = $json->user;
			}
			if (!isset($json->type)) {
				$this->status = new RelayStatus(
					RelayStatus::INIT,
					'Received highway message without type'
				);
				$this->logger->warning("Received highway message without type on  {relay}", [
					"relay" => $this->relay->getName(),
					"message" => $json,
				]);
				$data = null;
				continue;
			}
			if ($json->type === static::TYPE_ROOM_INFO && isset($this->initCallback)) {
				$this->status = new RelayStatus(RelayStatus::READY, "ready");
				$callback = $this->initCallback;
				unset($this->initCallback);
				$callback();
				$data = null;
				continue;
			}
			if ($json->type === static::TYPE_SUCCESS && isset($this->deInitCallback)) {
				$callback = $this->deInitCallback;
				unset($this->deInitCallback);
				$callback();
				$data = null;
				continue;
			}
			if ($json->type === static::TYPE_ERROR) {
				$this->logger->error("Highway error on {relay}: {message}", [
					"relay" => $this->relay->getName(),
					"message" => $json->message,
				]);
				$this->status = new RelayStatus(RelayStatus::ERROR, $json->message);
				$data = null;
				continue;
			}
			if ($json->type === static::TYPE_HELLO) {
				$data = null;
				continue;
			}
			if ($json->type === static::TYPE_LEAVE) {
				// Set all reported users of this bot offline
				if (isset($msg->sender)) {
					$this->relay->setClientOffline($msg->sender);
				}
				$data = null;
				continue;
			}
			if ($json->type !== static::TYPE_MESSAGE) {
				$data = null;
				continue;
			}
			if (!isset($json->body)) {
				$this->status = new RelayStatus(
					RelayStatus::INIT,
					'Received highway message without body'
				);
				$this->logger->error("Received highway message without body on {relay}", [
					"relay" => $this->relay->getName(),
					"message" => $json
				]);
				$data = null;
				continue;
			}
			$data = $json->body;
		}
		$msg->packages = array_values(array_filter($msg->packages));
		return $msg;
	}
}
