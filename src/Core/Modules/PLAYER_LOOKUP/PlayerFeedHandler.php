<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Amp\{Promise, Success};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{ConfigFile, EventFeed, EventFeedHandler, LoggerWrapper, ModuleInstance, Nadybot, SettingEvent};
use Throwable;

#[
	NCA\Instance,
]
class PlayerFeedHandler extends ModuleInstance implements EventFeedHandler {
	public const FEED_ROOM = 'bork_updates';
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventFeed $eventFeed;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Receive dynamic character updates via Highway bots */
	#[NCA\Setting\Boolean]
	public bool $lookupFeedEnabled = true;

	#[NCA\Setup]
	public function setup(): void {
		if ($this->lookupFeedEnabled) {
			$this->eventFeed->registerEventFeedHandler(self::FEED_ROOM, $this);
		}
	}

	#[NCA\Event(
		name: 'setting(lookup_feed_enabled)',
		description: "Subscribe/unsubscribe from event feed",
	)]
	public function toggleEventFeed(SettingEvent $event): void {
		if ($event->newValue->typed() === true) {
			$this->eventFeed->registerEventFeedHandler(self::FEED_ROOM, $this);
		} else {
			$this->eventFeed->unregisterEventFeedHandler(self::FEED_ROOM, $this);
		}
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		$mapper = new ObjectMapperUsingReflection();
		try {
			$playerInfo = $mapper->hydrateObject(PlayerInfo::class, $data);
			$player = $playerInfo->toPlayer();
			$this->playerManager->update($player);
			$this->chatBot->id[$playerInfo->name] = $playerInfo->uid;
			$this->chatBot->id[$playerInfo->uid] = $playerInfo->name;
		} catch (UnableToHydrateObject $e) {
			$this->logger->error("Format of Char-Info-API has changed: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		} catch (Throwable $e) {
			$this->logger->error("Error handling character update: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}

		/** @var Promise<void> */
		$result = new Success();
		return $result;
	}
}
