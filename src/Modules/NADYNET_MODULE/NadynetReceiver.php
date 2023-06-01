<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use function Amp\Promise\rethrow;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, ObjectMapperUsingReflectionHydrationTest};
use Nadybot\Core\Highway;
use Nadybot\Core\Modules\ALTS\{AltsController, NickController};
use Nadybot\Core\Routing\{Character, RoutableEvent, RoutableMessage, Source};
use Nadybot\Core\{Attributes as NCA, ConfigFile, EventFeed, MessageHub, MessageReceiver, Nadybot};

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

	#[NCA\Inject]
	public MessageHub $msgHub;

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
		if (!$this->nadynetController->nadynetRouteInternally) {
			return true;
		}
		$senderHops = $event->getPath();
		if (empty($senderHops)) {
			return true;
		}
		$relayedTo = $this->msgHub->getReceiversFor(
			$senderHops[0]->type . "(" . $senderHops[0]->name . ")"
		);
		$nadynetReceivers = $this->msgHub->getReceiversFor("nadynet({$channel})");
		$missingReceivers = array_diff($nadynetReceivers, $relayedTo);

		$rMsg = new RoutableMessage($message->message);
		$rMsg->setCharacter(new Character(
			name: $message->sender_name,
			id: $message->sender_uid,
			dimension: $message->dimension,
		));
		$rMsg->prependPath(new Source(
			type: "nadynet",
			name: strtolower($message->channel),
			label: $message->channel,
			dimension: $message->dimension,
		));
		foreach ($missingReceivers as $missingReceiver) {
			$handler = $this->msgHub->getReceiver($missingReceiver);
			if (isset($handler)) {
				$handler->receive($rMsg, $missingReceiver);
			}
		}
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
