<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{EventFeed, Hydrator, ModuleInstance, Nadybot};
use Nadybot\Core\Events\SettingEvent;
use Nadybot\Core\Types\EventFeedHandler;
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
	private BotConfig $config;

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

	/** Subscribe/unsubscribe from event feed */
	#[NCA\HandlesEvent(mask: 'setting(lookup_feed_enabled)')]
	public function toggleEventFeed(SettingEvent $event): void {
		if ($event->newValue->typed() === true) {
			$this->eventFeed->registerEventFeedHandler(self::FEED_ROOM, $this);
		} else {
			$this->eventFeed->unregisterEventFeedHandler(self::FEED_ROOM, $this);
		}
	}

	/** {@inheritDoc} */
	public function handleEventFeedMessage(string $room, array $data): void {
		try {
			$playerInfo = Hydrator::hydrate(PlayerInfo::class, $data);
			$player = $playerInfo->toPlayer();
			$this->playerManager->update($player);
			if ($player->dimension === $this->config->main->dimension) {
				$this->chatBot->cacheUidNameMapping($playerInfo->name, $playerInfo->uid);
			}
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
