<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Closure;
use JsonMapper;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	EventManager,
	LoggerWrapper,
	Nadybot,
	Routing\Character,
	Routing\Events\Base,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	SyncEvent,
};
use Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot\RelayCharacter;
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	RELAY_MODULE\Relay,
	RELAY_MODULE\RelayMessage,
	RELAY_MODULE\RelayProtocol\Nadybot\OnlineBlock,
	RELAY_MODULE\RelayProtocol\Nadybot\OnlineList,
};
use Safe\Exceptions\JsonException;
use stdClass;
use Throwable;

#[
	NCA\RelayProtocol(
		name: "nadynative",
		description: "This is the native protocol if your relay consists\n".
			"only of Nadybots 5.2 or newer. It supports message-passing,\n".
			"proper colorization and event-passing."
	),
	NCA\Param(
		name: "sync-online",
		type: "bool",
		description: "Sync the online list with the other bots of this relay",
		required: false
	)
]
class NadyNative implements RelayProtocolInterface {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public EventManager $eventManager;
	protected static int $supportedFeatures = 3;

	protected Relay $relay;

	protected bool $syncOnline = true;

	public function __construct(bool $syncOnline=true) {
		$this->syncOnline = $syncOnline;
	}

	public function send(RoutableEvent $event): array {
		$this->logger->debug("Relay {relay} received event to route", [
			"relay" => $this->relay->getName(),
			"event" => $event,
		]);
		$event = clone $event;
		if (isset($event->data) && ($event->data instanceof Base)) {
			$event->data->renderPath = true;
		}
		if (is_string($event->data)) {
			$event->data = str_replace("<myname>", $this->chatBot->char->name, $event->data);
		} elseif (is_object($event->data) && !($event->data instanceof SyncEvent) && is_string($event->data->message??null)) {
			$event->data->message = str_replace("<myname>", $this->chatBot->char->name, $event->data->message??"");
		}
		try {
			$data = \Safe\json_encode($event, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
		} catch (JsonException $e) {
			$this->logger->error(
				'Cannot send event via Nadynative protocol: '.
				$e->getMessage(),
				["exception" => $e]
			);
			return [];
		}
		return [$data];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		$this->logger->debug("Relay {relay} received message to route", [
			"relay" => $this->relay->getName(),
			"message" => $message,
		]);
		if (empty($message->packages)) {
			return null;
		}
		$serialized = array_shift($message->packages);
		try {
			if (is_string($serialized)) {
				$data = \Safe\json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
			} else {
				$data = $serialized;
			}
		} catch (JsonException $e) {
			$this->logger->error(
				'Invalid data received via Nadynative protocol',
				[
					"exception" => $e,
					"data" => $serialized,
				]
			);
			return null;
		}
		$data->type ??= RoutableEvent::TYPE_MESSAGE;
		switch ($data->type) {
			case "online_list_request":
				if ($this->syncOnline) {
					$this->sendOnlineList();
				}
				return null;
			case "online_list":
				if ($this->syncOnline) {
					$this->handleOnlineList($message->sender, $data);
				}
				return null;
		}
		$event = new RoutableEvent();
		foreach (($data->path??[]) as $hop) {
			$source = new Source(
				$hop->type,
				$hop->name,
				$hop->label??null,
				$hop->dimension??null
			);
			$event->appendPath($source);
		}
		$event->data = $data->data??null;
		$event->type = $data->type;
		if (isset($data->char) && is_object($data->char) && ($data->char instanceof stdClass) && isset($data->char->name)) {
			$event->setCharacter(
				new Character(
					$data->char->name,
					$data->char->id??null,
					$data->char->dimension??null
				)
			);
		}
		if ($event->type === RoutableEvent::TYPE_EVENT
			&& is_object($event->data)
			&& ($event->data instanceof stdClass)
			&& ($event->data->type??null) === Online::TYPE
			&& isset($message->sender)
			&& $this->syncOnline
		) {
			$event->data = (new JsonMapper())->map($event->data, new Online());
			$this->logger->debug("Received online event for {relay}", [
				"relay" => $this->relay->getName(),
				"event" => $event,
			]);
			$this->handleOnlineEvent($message->sender, $event);
		}
		if ($event->type === RoutableEvent::TYPE_EVENT
			&& is_object($event->data)
			&& fnmatch("sync(*)", $event->data->type??"", FNM_CASEFOLD)
		) {
			$this->logger->debug("Received sync event for {relay}", [
				"relay" => $this->relay->getName(),
				"event" => $event,
			]);
			$this->handleExtSyncEvent($event->data);
			return null;
		}
		$this->logger->debug("Received routable event for {relay}", [
			"relay" => $this->relay->getName(),
			"event" => $event,
		]);
		return $event;
	}

	public function init(callable $callback): array {
		$callback();
		$this->eventManager->subscribe("sync(*)", [$this, "handleSyncEvent"]);
		if ($this->syncOnline) {
			return [
				$this->jsonEncode($this->getOnlineList()),
				$this->jsonEncode((object)["type" => "online_list_request"]),
			];
		}
		return [];
	}

	public function deinit(callable $callback): array {
		$this->eventManager->unsubscribe("sync(*)", [$this, "handleSyncEvent"]);
		$callback();
		return [];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function handleSyncEvent(SyncEvent $event): void {
		if (isset($event->sourceBot, $event->sourceDimension)

			&& ($event->sourceDimension !== $this->config->dimension
				|| $event->sourceBot !== $this->chatBot->char->name)
		) {
			// We don't want to relay other bots' events
			return;
		}
		if (!$this->relay->allowOutSyncEvent($event) && !$event->forceSync) {
			return;
		}
		$sEvent = clone $event;
		$sEvent->sourceBot = $this->chatBot->char->name;
		$sEvent->sourceDimension = $this->config->dimension;
		$rEvent = new RoutableEvent();
		$rEvent->setType($rEvent::TYPE_EVENT);
		$rEvent->setData($sEvent);
		$this->relay->receive($rEvent, "*");
	}

	public static function supportsFeature(int $feature): bool {
		return (static::$supportedFeatures & $feature) === $feature;
	}

	protected function handleExtSyncEvent(object $event): void {
		try {
			$sEvent = new SyncEvent();
			foreach (get_object_vars($event) as $key => $value) {
				$sEvent->{$key} = $value;
			}
			if ($sEvent->isLocal()) {
				return;
			}
		} catch (Throwable $e) {
			$this->logger->error("Invalid sync-event received: " . $e->getMessage(), ["exception" => $e]);
			return;
		}
		if (!$this->relay->allowIncSyncEvent($sEvent)) {
			return;
		}
		$this->eventManager->fireEvent($sEvent);
	}

	protected function sendOnlineList(): void {
		$this->relay->receiveFromMember(
			$this,
			[$this->jsonEncode($this->getOnlineList())]
		);
	}

	protected function handleOnlineList(?string $sender, object $onlineList): void {
		/** @var OnlineList $onlineList */
		if (!isset($sender)) {
			// If we don't know the sender of that package, we can never
			// put people to offline when the bot leaves
			return;
		}
		foreach ($onlineList->online as $block) {
			$this->handleOnlineBlock($sender, $block);
		}
	}

	protected function handleOnlineBlock(string $sender, object $block): void {
		/** @var OnlineBlock $block */
		$hops = [];
		$lastHop = null;
		foreach ($block->path as $hop) {
			$source = new Source(
				$hop->type,
				$hop->name,
				$hop->label??null,
				$hop->server ?? null
			);
			$hops []= $source->render($lastHop);
			$lastHop = $source;
		}
		$hops = array_filter($hops);
		$where = join(" ", $hops);
		foreach ($block->users as $user) {
			$this->relay->setOnline(
				$sender,
				$where,
				$user->name,
				$user->id??null,
				$user->dimension??null,
				$user->main,
			);
		}
	}

	protected function handleOnlineEvent(string $sender, RoutableEvent $event): void {
		$hops = [];
		$lastHop = null;
		foreach ($event->path as $hop) {
			$hops []= $hop->render($lastHop);
			$lastHop = $hop;
		}
		$hops = array_filter($hops);
		$where = join(" ", $hops);

		/** @var Online */
		$llEvent = $event->data;
		if (!isset($llEvent->char)) {
			return;
		}
		$call = $llEvent->online
			? Closure::fromCallable([$this->relay, "setOnline"])
			: Closure::fromCallable([$this->relay, "setOffline"]);
		$call($sender, $where, $llEvent->char->name, $llEvent->char->id, $llEvent->char->dimension, $llEvent->main??null);
	}

	protected function getOnlineList(): OnlineList {
		$onlineList = new OnlineList();
		$onlineOrg = $this->onlineController->getPlayers('guild', $this->chatBot->char->name);
		$isOrg = strlen($this->config->orgName);
		if ($isOrg) {
			$block = new OnlineBlock();
			$orgLabel = $this->settingManager->getString("relay_guild_abbreviation");
			if (!isset($orgLabel) || $orgLabel === "none") {
				$orgLabel = null;
			}
			$block->path []= new Source(
				Source::ORG,
				$this->config->orgName,
				$orgLabel
			);
			foreach ($onlineOrg as $player) {
				$char = new RelayCharacter(
					$player->name,
					$player->charid ?? null,
					$player->dimension ?? $this->config->dimension
				);
				$char->main = $this->altsController->getMainOf($player->name);
				$block->users []= $char;
			}
			$onlineList->online []= $block;
		}

		$privBlock = new OnlineBlock();
		$onlinePriv = $this->onlineController->getPlayers('priv', $this->chatBot->char->name);
		$privLabel = null;
		if (isset($block)) {
			$privLabel = "Guest";
			$privBlock->path = $block->path;
		}
		$privBlock->path []= new Source(
			Source::PRIV,
			$this->chatBot->char->name,
			$privLabel,
		);
		foreach ($onlinePriv as $player) {
			$char = new RelayCharacter(
				$player->name,
				$player->charid ?? null,
				$player->dimension ?? $this->config->dimension
			);
			$char->main = $this->altsController->getMainOf($player->name);
			$privBlock->users []= $char;
		}
		$onlineList->online []= $privBlock;
		return $onlineList;
	}

	protected function jsonEncode(mixed $data): string {
		return \Safe\json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
	}
}
