<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use function Amp\{delay};

class LeakyBucket {
	/**
	 * The packet queue for one sender
	 *
	 * @var Message[]
	 */
	public array $queue;

	/** How many seconds between refilling the bucket with 1 item */
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

	/** When did we last check for refill */
	protected float $lastRefill;

	protected ?float $emptySince=null;

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
	}

	public function getSize(): int {
		return count($this->queue);
	}

	public function clear(): int {
		$size = count($this->queue);
		$this->queue = [];
		return $size;
	}

	public function getEmptySince(): ?float {
		return $this->emptySince;
	}

	/** Add a packet to the end of the chat queue */
	public function push(Message $item): void {
		$this->queue []= $item;
		$this->emptySince = null;
	}

	/**
	 * Get the number seconds until another packet can be sent
	 *
	 * @return float -1 if nothing to send, 0 if now otherwise fractional seconds
	 */
	public function getTTNP(): float {
		if (count($this->queue) === 0) {
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
		if ($this->bucketFill < 1) {
			$timeSinceLastRefill = $current - $this->lastRefill;
			$timeTillNextRefill = $this->refillIntervall - $timeSinceLastRefill;
			return $timeTillNextRefill;
		}
		return 0;
	}

	/** Get the next packet to process or null if none */
	public function getNext(): ?Message {
		if (count($this->queue) === 0) {
			return null;
		}
		$ttnp = -1;
		while (($ttnp = $this->getTTNP()) > 0) {
			delay($ttnp);
		}
		if ($ttnp < 0) {
			return null;
		}

		$item = array_shift($this->queue);
		$this->bucketFill--;
		if (count($this->queue) === 0) {
			$this->emptySince = microtime(true);
		}
		return $item;
	}
}
