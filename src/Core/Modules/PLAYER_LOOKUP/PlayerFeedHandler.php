<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{EventFeed, EventFeedHandler, ModuleInstance, Nadybot, SettingEvent};
use Psr\Log\LoggerInterface;
use Throwable;

#[
	NCA\Instance,
]
class PlayerFeedHandler extends ModuleInstance implements EventFeedHandler {
	public const FEED_ROOM = 'bork_updates';

	/** Receive dynamic character updates via Highway bots */
	#[NCA\Setting\Boolean]
	public bool $lookupFeedEnabled = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private EventFeed $eventFeed;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Setup]
	public function setup(): void {
		if ($this->lookupFeedEnabled) {
			$this->eventFeed->registerEventFeedHandler(self::FEED_ROOM, $this);
		}
	}

	#[NCA\Event(
		name: 'setting(lookup_feed_enabled)',
		description: 'Subscribe/unsubscribe from event feed',
	)]
	public function toggleEventFeed(SettingEvent $event): void {
		if ($event->newValue->typed() === true) {
			$this->eventFeed->registerEventFeedHandler(self::FEED_ROOM, $this);
		} else {
			$this->eventFeed->unregisterEventFeedHandler(self::FEED_ROOM, $this);
		}
	}

	/** @param array<string,mixed> $data */
	public function handleEventFeedMessage(string $room, array $data): void {
		$mapper = new ObjectMapperUsingReflection();
		try {
			$playerInfo = $mapper->hydrateObject(PlayerInfo::class, $data);
			$player = $playerInfo->toPlayer();
			$this->playerManager->update($player);
			$this->chatBot->cacheUidNameMapping($playerInfo->name, $playerInfo->uid);
		} catch (UnableToHydrateObject $e) {
			$this->logger->error('Format of Char-Info-API has changed: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		} catch (Throwable $e) {
			$this->logger->error('Error handling character update: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
