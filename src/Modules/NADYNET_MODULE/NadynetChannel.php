<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use function Amp\Promise\rethrow;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, ObjectMapperUsingReflectionHydrationTest};
use Nadybot\Core\Highway;
use Nadybot\Core\Modules\ALTS\{AltsController, NickController};
use Nadybot\Core\Routing\{RoutableEvent};
use Nadybot\Core\{Attributes as NCA, ConfigFile, EventFeed, MessageEmitter, MessageReceiver, Nadybot};

class NadynetChannel implements MessageEmitter, MessageReceiver {
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

	public function __construct(
		private string $channel
	) {
	}

	public function getChannelName(): string {
		return "nadynet({$this->channel})";
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
			channel: $destination,
			message: $event->getData(),
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
}
