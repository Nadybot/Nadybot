<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{Config\BotConfig, EventFeedHandler, EventManager, ModuleInstance, SyncEvent};
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;
use Psr\Log\LoggerInterface;

#[
	NCA\Instance,
	NCA\HandlesEventFeed('boss_timers')
]
class FeedHandler extends ModuleInstance implements EventFeedHandler {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** @param array<string,mixed> $data */
	public function handleEventFeedMessage(string $room, array $data): void {
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
		if ($event->sourceDimension !== $this->config->main->dimension) {
			$this->logger->info("Event is for a different dimension");
			return;
		}
		$this->eventManager->fireEvent($event);
	}
}
