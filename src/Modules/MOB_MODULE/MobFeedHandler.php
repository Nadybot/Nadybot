<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE;

use function Amp\call;
use Amp\{Promise, Success};
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Generator;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{ConfigFile, EventFeedHandler, EventManager, LoggerWrapper, ModuleInstance, SyncEvent};
use Nadybot\Modules\MOB_MODULE\FeedMessage\Spawn;
use Nadybot\Modules\MOB_MODULE\{FeedMessage};
use Throwable;

#[
	NCA\Instance,
	NCA\HandlesEventFeed('mob_events'),
	NCA\ProvidesEvent("mob-spawn"),
	NCA\ProvidesEvent("mob-death"),
]
class MobFeedHandler extends ModuleInstance implements EventFeedHandler {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public MobController $mobCtrl;

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
			$this->logger->notice("Reloading mob data");
			yield from $this->mobCtrl->initMobsFromApi();
		});
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		return call(function () use ($data): void {
			/** @var array<string,class-string> */
			$mapping = [
				FeedMessage\Base::CORPSE => FeedMessage\Corpse::class,
				FeedMessage\Base::DEATH  => FeedMessage\Death::class,
				FeedMessage\Base::HP     => FeedMessage\HP::class,
				FeedMessage\Base::SPAWN  => FeedMessage\Spawn::class,
			];
			$mapper = new ObjectMapperUsingReflection();
			try {
				$baseInfo = $mapper->hydrateObject(FeedMessage\Base::class, $data);
				$class = $mapping[$baseInfo->event] ?? null;
				if (!isset($class)) {
					$this->logger->notice("Unknown mob-event {type}", [
						"type" => $baseInfo->event,
					]);
					return;
				}

				/** @var FeedMessage\Base */
				$update = $mapper->hydrateObject($class, $data);
				$mob = $this->mobCtrl->mobs[$update->type][$update->key]??null;
				if (!isset($mob)) {
					$this->logger->info("Event for unknown mob: {type}/{key}", [
						"type" => $update->type,
						"key" => $update->key,
					]);
					return;
				}
				// Tracker was restarted, ignore
				if ($update instanceof Spawn && $update->instance === $mob->instance) {
					return;
				}
				$newMob = $update->processUpdate($mob);
				$this->mobCtrl->mobs[$update->type][$update->key] = $newMob;
				if (in_array($update->event, [$update::DEATH, $update::SPAWN])) {
					$event = new MobEvent(
						mob: $newMob,
						type: "mob-{$update->event}",
					);
					$this->eventManager->fireEvent($event);
				}
			} catch (UnableToHydrateObject $e) {
				$this->logger->error("Format of mob-API has changed: {error}", [
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
			} catch (Throwable $e) {
				$this->logger->error("Error getting the mob-timers from the api: {error}", [
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
			}
		});
	}
}
