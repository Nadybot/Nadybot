<?php

namespace Budabot\Core;

class EventLoop {

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\EventManager $eventManager
	 * @Inject
	 */
	public $eventManager;

	/**
	 * @var \Budabot\Core\SocketManager $socketManager
	 * @Inject
	 */
	public $socketManager;

	/**
	 * @var \Budabot\Core\AMQP
	 * @Inject
	 */
	public $amqp;

	/**
	 * @var \Budabot\Core\Timer $timer
	 * @Inject
	 */
	public $timer;

	public function execSingleLoop() {
		$this->chatBot->processAllPackets();

		if ($this->chatBot->isReady()) {
			$this->socketManager->checkMonitoredSockets();
			$this->eventManager->executeConnectEvents();
			$this->timer->executeTimerEvents();
			$this->amqp->processMessages();
			$this->eventManager->crons();

			usleep(10000);
		}
	}
}
