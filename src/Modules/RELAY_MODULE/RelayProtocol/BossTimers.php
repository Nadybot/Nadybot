<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	EventManager,
	LoggerWrapper,
	Routing\RoutableEvent,
	SyncEvent,
};
use Nadybot\Modules\{
	RELAY_MODULE\Relay,
	RELAY_MODULE\RelayMessage,
	WEBSERVER_MODULE\JsonImporter,
};
use Safe\Exceptions\JsonException;
use stdClass;

class BossTimers implements RelayProtocolInterface {
	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;
	protected Relay $relay;

	public function send(RoutableEvent $event): array {
		return [];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$serialized = array_shift($message->packages);
		try {
			/** @var stdClass */
			$data = \Safe\json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			if (!isset($data->sourceDimension) || !isset($data->type)) {
				throw new Exception("Incomplete data received.");
			}
		} catch (JsonException $e) {
			$this->logger->error(
				'Invalid data received via bosstimer protocol: {data}',
				["exception" => $e, "data" => $serialized]
			);
			return null;
		}
		$data->sourceBot ??= "_Nadybot";
		$data->forceSync ??= false;

		/** @var SyncEvent */
		$event = JsonImporter::convert(SyncEvent::class, $data);
		foreach (get_object_vars($data) as $key => $value) {
			if (!isset($event->{$key})) {
				$event->{$key} = $value;
			}
		}
		$this->eventManager->fireEvent($event);
		return null;
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public static function supportsFeature(int $feature): bool {
		return false;
	}
}
