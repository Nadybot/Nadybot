<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

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

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public function send(RoutableEvent $event): array {
		return [];
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$serialized = array_shift($msg->packages);
		try {
			$data = json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
			if (!isset($data->sourceBot) || !isset($data->sourceDimension) || !isset($data->type)) {
				throw new Exception("Incomplete data received.");
			}
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				'Invalid data received via bosstimer protocol: '.$data,
				$e
			);
			return null;
		}
		$event = JsonImporter::convert(SyncEvent::class, $data);
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
