<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use EventSauce\ObjectHydrator\UnableToSerializeObject;
use Exception;
use Nadybot\Core\{Attributes as NCA, LoggerWrapper};
use Nadybot\Core\Highway\In;
use Nadybot\Core\Highway\Out;
use Nadybot\Core\Highway\Parser;
use Nadybot\Core\Highway\ParserHighwayException;
use Nadybot\Core\Highway\ParserJsonException;
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
		name: "highway",
		description: "This is the highway protocol, spoken by the highway websocket-server.\n".
			"It will broadcast incoming messages to all clients in the same room.\n".
			"Room names can be picked freely as long as they are at least 32 characters\n".
			"long. They should be as random as possible to prevent unauthorized\n".
			"access to messages.\n".
			"Shorter room names are system rooms and by definition read-only.\n".
			"For further security, using an encryption layer is recommended."
	),
	NCA\Param(
		name: "room",
		type: "string[]",
		description: "The room(s) to join. Must be at least 32 characters long if you want to be able to send.",
		required: true
	)
]
class Highway implements RelayLayerInterface, StatusProvider {
	public const TYPE_MESSAGE = "message";
	public const TYPE_JOIN = "join";
	public const TYPE_LEAVE = "leave";
	public const TYPE_ROOM_INFO_0_1 = "room-info";
	public const TYPE_ROOM_INFO = "room_info";
	public const TYPE_SUCCESS = "success";
	public const TYPE_ERROR = "error";
	public const TYPE_HELLO = "hello";

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var string[] */
	protected array $rooms = [];

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @var ?callable */
	protected $initCallback = null;

	/** @var ?callable */
	protected $deInitCallback = null;

	/** @param string[] $rooms */
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

	private function encodePackage(Out\OutPackage $package): string {
		$mapper = new ObjectMapperUsingReflection();
		$json = $mapper->serializeObject($package);
		unset($json['id']);
		return \Safe\json_encode($json, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
	}

	public function init(callable $callback): array {
		$cmd = [];
		foreach ($this->rooms as $room) {
			$joinMsg = new Out\Join(room: $room);
			try {
				$encoded = $this->encodePackage($joinMsg);
			} catch (JsonException|UnableToSerializeObject $e) {
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
		if (!isset($this->status)) {
			$callback();
			return [];
		}
		foreach ($this->rooms as $room) {
			$leaveMsg = new Out\Leave(room: $room);
			try {
				$encoded = $this->encodePackage($leaveMsg);
			} catch (JsonException|UnableToSerializeObject $e) {
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
		$this->logger->debug("Encoding packets on {relay} to highway", [
			"relay" => $this->relay->getName(),
			"packets" => $data,
		]);
		foreach ($data as $packet) {
			foreach ($this->rooms as $room) {
				$msg = new Out\Message(room: $room, body: $packet);
				try {
					$encoded []= $this->encodePackage($msg);
				} catch (JsonException|UnableToSerializeObject $e) {
					$this->logger->error(
						"Unable to encode the relay data into highway protocol: ".
							$e->getMessage(),
						["exception" => $e]
					);
					continue;
				}
			}
		}
		$this->logger->debug(
			"Encoding packets on {relay} to highway finished successfully",
			[
				"relay" => $this->relay->getName(),
				"encoded" => $encoded,
			]
		);
		return $encoded;
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$data) {
			try {
				$this->logger->debug("Received highway message on relay {relay}", [
					"relay" => $this->relay->getName(),
					"message" => $data,
				]);
				$package = Parser::parseHighwayPackage($data);
				$this->logger->debug("Received highway package {package} on relay {relay}", [
					"relay" => $this->relay->getName(),
					"package" => $package,
				]);
			} catch (ParserJsonException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Unable to decode highway message: " . $e->getMessage()
				);
				$this->logger->error("Unable to decode highway message on {relay}: {error}", [
					"relay" => $this->relay->getName(),
					"message" => $data,
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
				$data = null;
				continue;
			} catch (ParserHighwayException $e) {
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					"Invalid highway package received: " . $e->getMessage()
				);
				$this->logger->error("Invalid highway package received on {relay}: {error}", [
					"relay" => $this->relay->getName(),
					"message" => $data,
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
				$data = null;
				continue;
			}
			if (($package instanceof In\RoomInfo) && isset($this->initCallback)) {
				$this->status = new RelayStatus(RelayStatus::READY, "ready");
				$callback = $this->initCallback;
				unset($this->initCallback);
				$callback();
				$data = null;
				continue;
			}
			if (($package instanceof In\Success) && isset($this->deInitCallback)) {
				$callback = $this->deInitCallback;
				unset($this->deInitCallback);
				$callback();
				$data = null;
				continue;
			}
			if ($package instanceof In\Error) {
				$this->logger->error("Highway error on {relay}: {message}", [
					"relay" => $this->relay->getName(),
					"message" => $package->message,
				]);
				$this->status = new RelayStatus(
					RelayStatus::ERROR,
					$package->message,
				);
				$data = null;
				continue;
			}
			if ($package instanceof In\Leave) {
				// Set all reported users of this bot offline
				$this->relay->setClientOffline($package->user);
				$data = null;
				continue;
			}
			if (!($package instanceof In\Message)) {
				$data = null;
				continue;
			}
			if (!isset($package->body)) {
				$this->status = new RelayStatus(
					RelayStatus::INIT,
					'Received highway message without body'
				);
				$this->logger->error("Received highway message without body on {relay}", [
					"relay" => $this->relay->getName(),
					"message" => $package,
				]);
				$data = null;
				continue;
			}
			$data = $package->body;
			$msg->sender = $package->user;
		}
		$msg->packages = array_values(array_filter($msg->packages));
		$this->logger->debug("Decoding highway message on relay {relay} done", [
			"relay" => $this->relay->getName(),
			"message" => $msg,
		]);
		return $msg;
	}
}
