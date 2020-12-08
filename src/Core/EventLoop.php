<?php declare(strict_types=1);

namespace Nadybot\Core;

use Throwable;

class EventLoop {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public AMQP $amqp;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	public function execSingleLoop(): void {
		try {
			$this->chatBot->processAllPackets();

			if ($this->chatBot->isReady()) {
				$socketActivity = $this->socketManager->checkMonitoredSockets();
				$this->eventManager->executeConnectEvents();
				$this->timer->executeTimerEvents();
				$this->amqp->processMessages();
				$this->eventManager->crons();

				if (!$socketActivity) {
					usleep(10000);
				} else {
					usleep(200);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log('ERROR', $e->getMessage() . PHP_EOL . $e->getTraceAsString(), $e);
		}
	}
}
