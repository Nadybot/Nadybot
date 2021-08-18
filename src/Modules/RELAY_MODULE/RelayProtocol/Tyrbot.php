<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use JsonException;
use Nadybot\Core\{
	LoggerWrapper,
	Nadybot,
	Routing\Character,
	Routing\RoutableEvent,
	Routing\Source,
	UserStateEvent,
};
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot\{
	BasePacket,
	Message,
	OnlineListRequest,
};
use Throwable;

/**
 * @RelayProtocol("tyrbot")
 * @Description("This is the enhanced protocol of Tyrbot. If your
 * 	relay consists only of Nadybots and Tyrbots, use this one.")
 */
class Tyrbot implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public Nadybot $chatBot;

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->encodeMessage($event);
		} elseif ($event->data instanceof UserStateEvent) {
			return $this->encodeUserStateChange($event, $event->data);
		}
		return [];
	}

	protected function encodeUserStateChange(RoutableEvent $r, UserStateEvent $event): array {
		$packet = [
			"type" => "user_state",
			"status" => ($event->type === "logon") ? "online" : "offline",
			"user" => [
				"name" => $event->sender,
			],
			"source" => [
				"name" => $r->path[0]->name,
				"type" => $this->nadyTypeToTyr($r->path[0]->type),
				"server" => (int)$this->chatBot->vars["dimension"]
			]
		];
		if (isset($r->path[0]->label)) {
			$packet["source"]["label"] = $r->path[0]->label;
		}
		$id = $this->chatBot->get_uid($event->sender);
		if ($id !== false) {
			$packet["user"]["id"] = $id;
		}
		return [json_encode($packet, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)];
	}

	protected function encodeMessage(RoutableEvent $event): array {
		$packet = [
			"type" => "message",
			"message" => $event->data,
		];
		if (isset($event->char)
			&& ($event->char->id ?? null) !== $this->chatBot->char->id) {
			$packet['user'] = ["name" => $event->char->name];
			if (isset($event->char->id)) {
				$packet['user']['id'] = $event->char->id;
			}
		} else {
			$packet['user'] = null;
		}
		$packet['source'] = [
			"name" => $event->path[0]->name,
			"server" => (int)$this->chatBot->vars["dimension"],
		];
		if (isset($event->path[0]->label)) {
			$packet['source']['label'] = $event->path[0]->label;
		}
		$lastHop = $event->path[count($event->path)-1];
		$packet['source']['type'] = $this->nadyTypeToTyr($lastHop->type);
		if (count($event->path) > 1) {
			$packet['source']['channel'] = $lastHop->label ?? $lastHop->name;
		}
		try {
			$data = json_encode($packet, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log('ERROR', "Error ecoding Tyrbot message: " . $e->getMessage());
			return [];
		}
		return [$data];
	}

	public function receive(string $serialized): ?RoutableEvent {
		$this->logger->log('DEBUG', "[Tyrbot] {$serialized}");
		try {
			$data = json_decode($serialized, true, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
			$identify = new BasePacket($data);
			return $this->decodeAndHandlePacket($identify, $data);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				"Invalid data received via Tyrbot protocol: {$serialized}"
			);
			return null;
		} catch (Throwable $e) {
			$this->logger->log(
				'ERROR',
				"Invalid Tyrbot-package received: {$serialized}"
			);
			$this->logger->log('ERROR', $e->getMessage());
			return null;
		}

		return null;
	}

	protected function decodeAndHandlePacket(BasePacket $identify, array $data): ?RoutableEvent {
		switch ($identify->type) {
			case $identify::MESSAGE:
				return $this->receiveMessage(new Message($data));
		}
		return null;
	}

	protected function receiveMessage(Message $packet): RoutableEvent {
		$event = new RoutableEvent();
		$event->type = $event::TYPE_MESSAGE;
		if (isset($packet->user)) {
			$event->setCharacter(new Character(
				$packet->user->name,
				$packet->user->id,
			));
		}
		if (isset($packet->source->channel)) {
			$event->prependPath(new Source(
				Source::ORG,
				$packet->source->name,
				$packet->source->label,
			));
			$event->appendPath(new Source(
				$this->tyrTypeToNady($packet->source->type),
				$packet->source->channel
			));
		} else {
			$event->appendPath(new Source(
				$this->tyrTypeToNady($packet->source->type),
				$packet->source->name,
				$packet->source->label ?? null
			));
		}
		$event->setData($this->convertFromTyrColors($packet->message));
		return $event;
	}

	protected function convertFromTyrColors(string $text): string {
		return preg_replace_callback(
			"/<\/(.*?)>/s",
			function (array $matches): string {
				$keep = ["font", "a", "img", "u", "i"];
				if (in_array($matches[1], $keep)) {
					return $matches[0];
				}
				return "<end>";
			},
			$text
		);
	}

	protected function nadyTypeToTyr(string $type): string {
		$map = [
			Source::ORG => "org",
			Source::PRIV => "priv",
			Source::PUB => "pub",
			Source::DISCORD_PRIV => "discord",
		];
		return $map[$type] ?? $type;
	}

	protected function tyrTypeToNady(string $type): string {
		$map = [
			"org" => Source::ORG,
			"priv" => Source::PRIV,
			"pub" => Source::PUB,
			"discord" => Source::DISCORD_PRIV,
		];
		return $map[$type] ?? $type;
	}

	public function init(callable $callback): array {
		$callback();
		return [json_encode(new OnlineListRequest())];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}
}
