<?php

namespace Nadybot\Core;

class EventLoop {

	/**
	 * @var \Nadybot\Core\Nadybot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Nadybot\Core\EventManager $eventManager
	 * @Inject
	 */
	public $eventManager;

	/**
	 * @var \Nadybot\Core\SocketManager $socketManager
	 * @Inject
	 */
	public $socketManager;

	/**
	 * @var \Nadybot\Core\AMQP
	 * @Inject
	 */
	public $amqp;

	/**
	 * @var \Nadybot\Core\Timer $timer
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
