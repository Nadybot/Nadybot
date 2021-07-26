<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\TransportProtocol;

use JsonException;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\RelayStackMember;
use Nadybot\Modules\RELAY_MODULE\Transport\TransportInterface;

class Highway implements RelayStackMember {
	protected string $room;

	/** @Logger */
	public LoggerWrapper $logger;

	public function __construct(string $room) {
		$this->room = $room;
	}

	public function init(): bool {
		$json = (object)[
			"type" => "command",
			"cmd" => "subscribe",
			"room" => $this->room,
		];
		try {
			$encoded = json_encode($json, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				"Unable to encode subscribe-command into highway protocol: ".
					$e->getMessage()
			);
			return false;
		}
		return $this->transport->send($encoded);
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
				$encoded []= json_encode($json, JSON_THROW_ON_ERROR);
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
