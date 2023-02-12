<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use function Amp\call;
use Amp\Promise;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{ConfigFile, EventFeedHandler, EventManager, LoggerWrapper, ModuleInstance, SyncEvent};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;

#[
	NCA\Instance,
	NCA\HandlesEventFeed('boss_timers')
]
class FeedHandler extends ModuleInstance implements EventFeedHandler {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise {
		return call(function () use ($data): void {
			if (!isset($data['sourceDimension']) || !isset($data['type'])) {
				throw new Exception("Incomplete data received.");
			}
			$data['sourceBot'] ??= "_Nadybot";
			$data['forceSync'] ??= false;

			/** @var SyncEvent */
			$event = JsonImporter::convert(SyncEvent::class, (object)$data);
			foreach ($data as $key => $value) {
				if (!isset($event->{$key})) {
					$event->{$key} = $value;
				}
			}
			if ($event->sourceDimension !== $this->config->dimension) {
				$this->logger->info("Event is for a different dimension");
				return;
			}
			$this->eventManager->fireEvent($event);
		});
	}
}
