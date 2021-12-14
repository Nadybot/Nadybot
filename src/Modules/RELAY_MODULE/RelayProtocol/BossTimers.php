<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Attributes as NCA;
use Exception;
use JsonException;
use Nadybot\Core\EventManager;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\SyncEvent;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;

class BossTimers implements RelayProtocolInterface {
	protected Relay $relay;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public function send(RoutableEvent $event): array {
		return [];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$serialized = array_shift($message->packages);
		try {
			$data = json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
			if (!isset($data->sourceDimension) || !isset($data->type)) {
				throw new Exception("Incomplete data received.");
			}
		} catch (JsonException $e) {
			$this->logger->error(
				'Invalid data received via bosstimer protocol: ' . ($data??"null"),
				["exception" => $e]
			);
			return null;
		}
		$data->sourceBot ??= "_Nadybot";
		$data->forceSync ??= false;
		$event = JsonImporter::convert(SyncEvent::class, $data);
		if (!isset($event)) {
			return null;
		}
		foreach ($data as $key => $value) {
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
