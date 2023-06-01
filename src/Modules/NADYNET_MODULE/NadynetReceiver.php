<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use function Amp\Promise\rethrow;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, ObjectMapperUsingReflectionHydrationTest};
use Nadybot\Core\Highway;
use Nadybot\Core\Modules\ALTS\{AltsController, NickController};
use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, ConfigFile, EventFeed, MessageReceiver, Nadybot};

class NadynetReceiver implements MessageReceiver {
	#[NCA\Inject]
	public EventFeed $eventFeed;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public NickController $nickController;

	#[NCA\Inject]
	public NadynetController $nadynetController;

	public function getChannelName(): string {
		return "nadynet";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if (!$this->nadynetController->nadynetEnabled) {
			return false;
		}
		$data = $event->getData();
		if (!is_string($data)) {
			return false;
		}
		if (!isset($this->eventFeed->connection)) {
			return false;
		}
		$prefix = $this->nadynetController->nadynetPrefix;
		if (!preg_match("/^" . preg_quote($prefix, "/") . "([a-zA-Z]+)/", $data, $matches)) {
			return false;
		}
		$channel = $this->guessChannel($matches[1]);
		if (!isset($channel)) {
			return false;
		}
		$character = $event->getCharacter();
		$message = new Message(
			dimension: $character?->dimension ?? $this->config->dimension,
			bot_uid: $this->chatBot->char->id,
			bot_name: $this->chatBot->char->name,
			sender_uid: $character?->id ?? $this->chatBot->char->id,
			sender_name: $character?->name ?? $this->chatBot->char->name,
			main: $character ? $this->altsController->getMainOf($character->name) : null,
			nick: $character ? $this->nickController->getNickname($character->name) : null,
			sent: time(),
			channel: $channel,
			message: ltrim(substr($data, strlen($matches[1])+1)),
		);
		$serializer = new ObjectMapperUsingReflection();
		$hwBody = $serializer->serializeObject($message);
		if (!is_array($hwBody)) {
			return false;
		}
		$packet = new Highway\Message(room: "nadynet", body: $hwBody);
		rethrow($this->eventFeed->connection->send($packet));
		return true;
	}

	private function guessChannel(string $selector): ?string {
		$channels = [];
		foreach (NadynetController::CHANNELS as $channel) {
			if (strncasecmp($channel, $selector, strlen($selector)) === 0) {
				$channels []= $channel;
			}
		}
		if (count($channels) !== 1) {
			return null;
		}
		return $channels[0];
	}
}
