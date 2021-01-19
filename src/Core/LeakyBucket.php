<?php declare(strict_types=1);

namespace Nadybot\Core;

if (!defined("AOC_PRIORITY_HIGH")) {
	define('AOC_PRIORITY_HIGH', 1000);
	define('AOC_PRIORITY_MED',   500);
	define('AOC_PRIORITY_LOW',   100);
}

class LeakyBucket implements QueueInterface {

	/**
	 * The packet queue for each priority (low, med, high)
	 *
	 * @var array<int,\Nadybot\Core\AOChatPacket[]> $queue
	 */
	public array $queue;

	/**
	 * The number of items in the queue for any priority
	 */
	protected int $queueSize;

	/**
	 * How many seconds between refilling the bucket with 1 item
	 */
	protected int $refillIntervall;

	/**
	 * The maximum number of messages to send in a row
	 * i.e. the size of the bucket
	 */
	protected int $bucketSize;

	/**
	 * The current fill level of the bucket
	 * i.e. how many mesages can we send right now
	 * without being rate-limited
	 */
	protected float $bucketFill;

	/**
	 * When did we last check for refill
	 */
	protected float $lastRefill;

	/**
	 * Is the limiter active?
	 */
	protected bool $enabled = true;

	/**
	 * Create a new Chat Queue with a burst of $limit messages and $increment seconds between messages after burst
	 *
	 * @param int $limit     How many messages can be sent before rate limit kicks in
	 * @param int $increment How long to wait between messages when rate limit is active
	 */
	public function __construct(int $bucketSize, int $refillIntervall) {
		$this->bucketFill = (float)$bucketSize;
		$this->bucketSize = $bucketSize;
		$this->refillInterval = $refillIntervall;
		$this->lastRefill = microtime(true);
		$this->queue = [];
		$this->queueSize = 0;
	}

	/**
	 * Add a packet to the end of the chat queue with priority $priority
	 */
	public function push(int $priority, AOChatPacket $item): void {
		if (isset($this->queue[$priority])) {
			$this->queue[$priority][] = $item;
		} else {
			$this->queue[$priority] = [$item];
			krsort($this->queue);
		}
		$this->queueSize++;
	}

	/**
	 * Get the next packet to process
	 *
	 * Takes queue priorities into account
	 */
	public function getNext(): ?AOChatPacket {
		if ($this->queueSize === 0) {
			return null;
		}

		foreach (array_keys($this->queue) as $priority) {
			while (true) {

				$current = microtime(true);
				$timePassed = $current - $this->lastRefill;

				$refillAmount = $timePassed / $this->refillInterval;
				if ($refillAmount >= 1.0) {
					$this->bucketFill += $refillAmount;
					$this->lastRefill = $current;
				}
				$this->bucketFill = min($this->bucketSize, $this->bucketFill);
				if ($this->enabled && $this->bucketFill < 1) {
echo("DEBUG:: queue full, cannot get packet\n");
					return null;
				}
				$item = array_shift($this->queue[$priority]);
				if ($item === null) {
					unset($this->queue[$priority]);
					break;
				}
				$this->bucketFill--;
				$this->queueSize = max($this->queueSize-1, 0);
				return $item;
			}
		}
		return null;
	}

	public function disable(): void {
		$this->enabled = false;
	}
}
