<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayProtocol("nadynative")
 * @Description("This is the native protocol if your relay consists
 * 	only of Nadybots 5.2 or newer. It supports message-passing,
 * 	proper colorization and event-passing.")
 */
class NadyNative implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Logger */
	public LoggerWrapper $logger;

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			try {
				$data = json_encode($event, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				$this->logger->log(
					'ERROR',
					'Cannot send message via Nadynative protocol: '.
					$e->getMessage()
				);
				return [];
			}
			return [$data];
		}
		return [];
	}

	public function receive(string $serialized): ?RoutableEvent {
		try {
			$data = json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				'Invalid data received via Nadynative protocol: '.$data
			);
			return null;
		}
		$event = new RoutableEvent();
		foreach (($data->path??[]) as $hop) {
			$source = new Source($hop->type, $hop->name, $hop->label??null);
			$event->appendPath($source);
		}
		$event->data = $data->data??null;
		$event->type = $data->type??RoutableEvent::TYPE_MESSAGE;
		if (isset($data->char)) {
			$event->setCharacter(
				new Character($data->char->name, $data->char->id??null)
			);
		}
		return $event;
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
}
