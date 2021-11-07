<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use JsonException;
use Nadybot\Core\{
	EventManager,
	LoggerWrapper,
	Nadybot,
	Routing\Character,
	Routing\Events\Online,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	SyncEvent,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlineController,
	RELAY_MODULE\Relay,
	RELAY_MODULE\RelayMessage,
	RELAY_MODULE\RelayProtocol\Nadybot\OnlineBlock,
	RELAY_MODULE\RelayProtocol\Nadybot\OnlineList,
};
use Throwable;

/**
 * @RelayProtocol("nadynative")
 * @Description("This is the native protocol if your relay consists
 * 	only of Nadybots 5.2 or newer. It supports message-passing,
 * 	proper colorization and event-passing.")
 * @Param(name='sync-online', description='Sync the online list with the other bots of this relay', type='bool', required=false)
 */
class NadyNative implements RelayProtocolInterface {
	protected static int $supportedFeatures = 3;

	protected Relay $relay;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public OnlineController $onlineController;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	protected bool $syncOnline = true;

	public function __construct(bool $syncOnline=true) {
		$this->syncOnline = $syncOnline;
	}

	public function send(RoutableEvent $event): array {
		$event = clone $event;
		$event->data->renderPath = true;
		if (is_string($event->data)) {
			$event->data = str_replace("<myname>", $this->chatBot->char->name, $event->data);
		} elseif (isset($event->data) && is_string($event->data->message??null)) {
			$event->data->message = str_replace("<myname>", $this->chatBot->char->name, $event->data->message);
		}
		try {
			$data = json_encode($event, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				'Cannot send event via Nadynative protocol: '.
				$e->getMessage(),
				$e
			);
			return [];
		}
		return [$data];
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$serialized = array_shift($msg->packages)??"null";
		try {
			$data = json_decode($serialized, false, 10, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->logger->log(
				'ERROR',
				'Invalid data received via Nadynative protocol: ' . $serialized,
				$e
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
					$this->handleOnlineList($msg->sender, $data);
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
		if (isset($data->char) && is_object($data->char)) {
			$event->setCharacter(
				new Character(
					$data->char->name,
					$data->char->id??null,
					$data->char->dimension??null
				)
			);
		}
		if ($event->type === RoutableEvent::TYPE_EVENT
			&& $event->data->type === Online::TYPE
			&& isset($msg->sender)
			&& $this->syncOnline
		) {
			$this->handleOnlineEvent($msg->sender, $event);
		}
		if ($event->type === RoutableEvent::TYPE_EVENT
			&& fnmatch("sync(*)", $event->data->type, FNM_CASEFOLD)
		) {
			$this->handleExtSyncEvent($event->data);
			return null;
		}
		return $event;
	}

	protected function handleExtSyncEvent(object $event): void {
		try {
			$sEvent = new SyncEvent();
			foreach ($event as $key => $value) {
				$sEvent->{$key} = $value;
			}
			if ($sEvent->isLocal()) {
				return;
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", "Invalid sync-event received: " . $e->getMessage(), $e);
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
		if (!isset($sender)) {
			// If we don't know the sender of that package, we can never
			// put people to offline when the bot leaves
			return;
		}
		/** @var OnlineList $onlineList */
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
				$hop->dimension??null
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
				$user->dimension??null
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
		$call = $llEvent->online ? [$this->relay, "setOnline"] : [$this->relay, "setOffline"];
		$call($sender, $where, $llEvent->char->name, $llEvent->char->id, $llEvent->char->dimension);
	}

	protected function getOnlineList(): OnlineList {
		$onlineList = new OnlineList();
		$onlineOrg = $this->onlineController->getPlayers('guild');
		$isOrg = strlen($this->chatBot->vars["my_guild"] ?? "") ;
		if ($isOrg) {
			$block = new OnlineBlock();
			$orgLabel = $this->settingManager->getString("relay_guild_abbreviation");
			if (!isset($orgLabel) || $orgLabel === "none") {
				$orgLabel = null;
			}
			$block->path []= new Source(
				Source::ORG,
				$this->chatBot->vars["my_guild"],
				$orgLabel
			);
			foreach ($onlineOrg as $player) {
				$block->users []= new Character(
					$player->name,
					$player->charid ?? null,
					$player->dimension ?? (int)$this->chatBot->vars['dimension']
				);
			}
			$onlineList->online []= $block;
		}

		$privBlock = new OnlineBlock();
		$onlinePriv = $this->onlineController->getPlayers('priv');
		$privLabel = null;
		if ($isOrg) {
			$privLabel = "Guest";
			$privBlock->path = $block->path;
		}
		$privBlock->path []= new Source(
			Source::PRIV,
			$this->chatBot->char->name,
			$privLabel,
		);
		foreach ($onlinePriv as $player) {
			$privBlock->users []= new Character(
				$player->name,
				$player->charid ?? null,
				$player->dimension ?? (int)$this->chatBot->vars['dimension']
			);
		}
		$onlineList->online []= $privBlock;
		return $onlineList;
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

	protected function jsonEncode($data): string {
		return json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
	}

	public function handleSyncEvent(SyncEvent $event): void {
		if (isset($event->sourceBot)
			&& isset($event->sourceDimension)
			&& ($event->sourceDimension !== (int)$this->chatBot->vars["dimension"]
				|| $event->sourceBot !== $this->chatBot->char->name)
		) {
			// We don't want to relay other bot's events
			return;
		}
		if (!$this->relay->allowOutSyncEvent($event) && !$event->forceSync) {
			return;
		}
		$sEvent = clone $event;
		$sEvent->sourceBot = $this->chatBot->char->name;
		$sEvent->sourceDimension = (int)$this->chatBot->vars["dimension"];
		$rEvent = new RoutableEvent();
		$rEvent->setType($rEvent::TYPE_EVENT);
		$rEvent->setData($sEvent);
		$this->relay->receive($rEvent, "*");
	}

	public static function supportsFeature(int $feature): bool {
		return (static::$supportedFeatures & $feature) === $feature;
	}
}
