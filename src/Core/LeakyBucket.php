<?php declare(strict_types=1);

namespace Nadybot\Core;

if (!defined("AOC_PRIORITY_HIGH")) {
	\Safe\define('AOC_PRIORITY_HIGH', 1000);
	\Safe\define('AOC_PRIORITY_MED',   500);
	\Safe\define('AOC_PRIORITY_LOW',   100);
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
	 * i.e. how many messages can we send right now
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
	 * @param int $bucketSize      How many messages can be sent before rate limit kicks in
	 * @param int $refillIntervall How long to wait between messages when rate limit is active
	 */
	public function __construct(int $bucketSize, int $refillIntervall) {
		$this->bucketFill = (float)$bucketSize;
		$this->bucketSize = $bucketSize;
		$this->refillIntervall = $refillIntervall;
		$this->lastRefill = microtime(true);
		$this->queue = [];
		$this->queueSize = 0;
	}

	public function getSize(): int {
		return count(array_merge(...array_values($this->queue)));
	}

	public function clear(): int {
		$size = $this->queueSize;
		$this->queue = [];
		$this->queueSize = 0;
		return $size;
	}

	/**
	 * Add a packet to the end of the chat queue with priority $priority
	 */
	public function push(int $priority, AOChatPacket $item): void {
		if (isset($this->queue[$priority])) {
			$this->queue[$priority] []= $item;
		} else {
			$this->queue[$priority] = [$item];
			krsort($this->queue);
		}
		$this->queueSize++;
	}

	/**
	 * Get the number seconds until another packet can be sent
	 *
	 * @return float -1 if nothing to send, 0 if now otherwise fractional seconds
	 */
	public function getTTNP(): float {
		if ($this->queueSize === 0) {
			return -1;
		}
		$current = microtime(true);
		$timePassed = $current - $this->lastRefill;

		$refillAmount = $timePassed / $this->refillIntervall;
		if ($refillAmount >= 1.0) {
			$this->bucketFill += $refillAmount;
			$this->lastRefill = $current;
			return 0;
		}
		$this->bucketFill = min($this->bucketSize, $this->bucketFill);
		if ($this->enabled && $this->bucketFill < 1) {
			$timeSinceLastRefill = $current - $this->lastRefill;
			$timeTillNextRefill = $this->refillIntervall - $timeSinceLastRefill;
			return $timeTillNextRefill;
		}
		return 0;
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

				$refillAmount = $timePassed / $this->refillIntervall;
				if ($refillAmount >= 1.0) {
					$this->bucketFill += $refillAmount;
					$this->lastRefill = $current;
				}
				$this->bucketFill = min($this->bucketSize, $this->bucketFill);
				if ($this->enabled && $this->bucketFill < 1) {
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
