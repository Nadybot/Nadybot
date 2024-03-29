<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use function Amp\call;
use Amp\Promise;
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Generator;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{ConfigFile, Event as CoreEvent, EventFeedHandler, EventManager, LoggerWrapper, ModuleInstance, SyncEvent};
use Nadybot\Modules\PVP_MODULE\{Event, FeedMessage};

#[
	NCA\Instance,
	NCA\HandlesEventFeed('tower_events'),
	NCA\ProvidesEvent("gas-update", "Gas on a tower field changes"),
	NCA\ProvidesEvent("site-update", "New  information about a tower site"),
	NCA\ProvidesEvent("tower-attack", "Someone attacks a tower site"),
	NCA\ProvidesEvent("tower-outcome", "A tower field gets destroyed"),
]
class TowerFeedHandler extends ModuleInstance implements EventFeedHandler {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public NotumWarsController $nwCtrl;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->eventManager->subscribe(
			"event-feed-reconnect",
			Closure::fromCallable([$this, "handleReconnect"])
		);
	}

	/** @return Promise<void> */
	public function handleReconnect(): Promise {
		return call(function (): Generator {
			$this->logger->notice("Reloading tower data");
			yield from $this->nwCtrl->initTowersFromApi();
			$this->logger->notice("Reloading attacks");
			yield from $this->nwCtrl->initAttacksFromApi();
			$this->logger->notice("Reloading outcomes");
			yield from $this->nwCtrl->initOutcomesFromApi();
		});
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		return call(function () use ($data): void {
			/** @var array<string,array{class-string,class-string}> */
			$mapping = [
				FeedMessage\Base::GAS_UPDATE => [
					FeedMessage\GasUpdate::class,
					Event\GasUpdate::class,
				],
				FeedMessage\Base::SITE_UPDATE => [
					FeedMessage\SiteUpdate::class,
					Event\SiteUpdate::class,
				],
				FeedMessage\Base::TOWER_ATTACK => [
					FeedMessage\TowerAttack::class,
					Event\TowerAttack::class,
				],
				FeedMessage\Base::TOWER_OUTCOME => [
					FeedMessage\TowerOutcome::class,
					Event\TowerOutcome::class,
				],
			];
			$mapper = new ObjectMapperUsingReflection();
			try {
				$baseInfo = $mapper->hydrateObject(FeedMessage\Base::class, $data);
				$specs = $mapping[$baseInfo->type] ?? null;
				if (!isset($specs)) {
					$this->logger->notice("Unknown tower-package {type}", [
						"type" => $baseInfo->type,
					]);
					return;
				}
				$info = $mapper->hydrateObject($specs[0], $data);
				$event = new ($specs[1])($info);
				if ($event instanceof CoreEvent) {
					$this->eventManager->fireEvent($event);
				}
			} catch (UnableToHydrateObject $e) {
				return;
			}
		});
	}
}
