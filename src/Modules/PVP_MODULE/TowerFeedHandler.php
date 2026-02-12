<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{EventManager, Events\Event as CoreEvent, Hydrator, ModuleInstance, Types\EventFeedHandler};
use Psr\Log\LoggerInterface;

#[
	NCA\Instance,
	NCA\HandlesEventFeed('tower_events'),
]
class TowerFeedHandler extends ModuleInstance implements EventFeedHandler {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	#[NCA\Setup]
	public function setup(): void {
		$this->eventManager->subscribe('event-feed-reconnect', $this->handleReconnect(...));
	}

	public function handleReconnect(): void {
		$this->logger->notice('Reloading tower data');
		$this->nwCtrl->initTowersFromApi();
		$this->logger->notice('Reloading attacks');
		$this->nwCtrl->initAttacksFromApi();
		$this->logger->notice('Reloading outcomes');
		$this->nwCtrl->initOutcomesFromApi();
	}

	/** {@inheritDoc} */
	public function handleEventFeedMessage(string $room, array $data): void {
		/** @var array<string,array{class-string,class-string}> */
		$mapping = [
			FeedMessage\Base::GAS_UPDATE => [
				FeedMessage\GasUpdate::class,
				Event\GasUpdateEvent::class,
			],
			FeedMessage\Base::SITE_UPDATE => [
				FeedMessage\SiteUpdate::class,
				Event\SiteUpdateEvent::class,
			],
			FeedMessage\Base::TOWER_ATTACK => [
				FeedMessage\TowerAttack::class,
				Event\TowerAttackEvent::class,
			],
			FeedMessage\Base::TOWER_OUTCOME => [
				FeedMessage\TowerOutcome::class,
				Event\TowerOutcomeEvent::class,
			],
		];
		try {
			$baseInfo = Hydrator::hydrate(FeedMessage\Base::class, $data);
			$specs = $mapping[$baseInfo->type] ?? null;
			if (!isset($specs)) {
				$this->logger->notice('Unknown tower-package {type}', [
					'type' => $baseInfo->type,
				]);
				return;
			}
			$info = Hydrator::hydrate($specs[0], $data);

			/**
			 * @psalm-suppress MixedMethodCall
			 *
			 * @mago-ignore analysis:unknown-class-instantiation
			 */
			$event = new ($specs[1])($info);
			$this->logger->info('Received tower-feed event {event}', ['event' => $event]);
			if ($event instanceof CoreEvent) {
				$this->eventManager->dispatch($event);
			}
		} catch (UnableToHydrateObject $e) {
			return;
		}
	}
}
