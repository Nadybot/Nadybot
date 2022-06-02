<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Nadybot\Core\Attributes as NCA;
use Throwable;

class EventLoop {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public SocketManager $socketManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<int,callable> */
	protected static array $callbacks = [];

	public function execSingleLoop(): void {
		try {
			$aoActivity = $this->chatBot->processNextPacket();

			if ($this->chatBot->isReady()) {
				// $socketActivity = $this->socketManager->checkMonitoredSockets();
				foreach (static::$callbacks as $i => $callback) {
					/** @phpstan-ignore-next-line */
					if (isset($callback) && is_callable($callback)) {
						$callback();
					}
				}

				if (/*!$socketActivity &&*/ !$aoActivity) {
					Loop::delay(10, [$this, __FUNCTION__]);
					return;
				} else {
					Loop::defer([$this, __FUNCTION__]);
					return;
				}
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
		}
		Loop::delay(10, [$this, __FUNCTION__]);
	}

	/** @deprecated version */
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

	/** @deprecated version */
	public static function rem(int $i): bool {
		if (!array_key_exists($i, static::$callbacks)) {
			return false;
		}
		unset(static::$callbacks[$i]);
		return true;
	}
}
