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
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<int,callable> */
	protected static array $callbacks = [];

	public function execSingleLoop(): void {
		try {
			$this->chatBot->processAllPackets();

			if ($this->chatBot->isReady()) {
				$socketActivity = $this->socketManager->checkMonitoredSockets();
				$this->eventManager->executeConnectEvents();
				$this->timer->executeTimerEvents();
				foreach (static::$callbacks as $i => $callback) {
					/** @phpstan-ignore-next-line */
					if (isset($callback) && is_callable($callback)) {
						$callback();
					}
				}
				$this->eventManager->crons();

				if (!$socketActivity) {
					usleep(10000);
				} else {
					usleep(200);
				}
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
		}
	}

	public static function add(callable $callback): int {
		$i = 0;
		while ($i < count(static::$callbacks)) {
			if (!array_key_exists($i, static::$callbacks)) {
				break;
			}
			$i++;
		}
		static::$callbacks[$i] = $callback;
		return $i;
	}

	public static function rem(int $i): bool {
		if (!array_key_exists($i, static::$callbacks)) {
			return false;
		}
		unset(static::$callbacks[$i]);
		return true;
	}
}
