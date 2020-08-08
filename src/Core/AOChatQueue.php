<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @author Oskari Saarenmaa <auno@auno.org>.
 * @license GPL
 *
 * AOChat, a PHP class for talking with the Anarchy Online chat servers.
 * It requires the sockets extension (to connect to the chat server..)
 * from PHP 4.2.0+ and either the GMP or BCMath extension (for generating
 * and calculating the login keys) to work.
 *
 * A disassembly of the official java chat client[1] for Anarchy Online
 * and Slicer's AO::Chat perl module[2] were used as a reference for this
 * class.
 *
 * [1]: <http://www.anarchy-online.com/content/community/forumsandchat/>
 * [2]: <http://www.hackersquest.org/ao/>
 */

define('AOC_PRIORITY_HIGH', 1000);
define('AOC_PRIORITY_MED',   500);
define('AOC_PRIORITY_LOW',   100);

class AOChatQueue {

	/**
	 * The packet queue for each priority (low, med, high)
	 *
	 * @var array<int,\Nadybot\Core\AOChatPacket[]> $queue
	 */
	public array $queue;

	/**
	 * The number of items in the queue for any priority
	 */
	public int $qsize;

	/**
	 * The next time we can send a message as UNIX timestamp
	 */
	public int $point;

	/**
	 * The amount of messages that can be sent before metering kicks in
	 */
	public int $limit;

	/**
	 * The amount of time in seconds to wait after the limit has been reached
	 */
	public int $increment;

	/**
	 * Create a new Chat Queue with a burst of $limit messages and $increment seconds between messages after burst
	 *
	 * @param int $limit     How many messages can be sent before rate limit kicks in
	 * @param int $increment How long to wait between messages when rate limit is active
	 */
	public function __construct(int $limit, int $increment) {
		$this->limit = $limit;
		$this->increment = $increment;
		$this->point = 0;
		$this->queue = array();
		$this->qsize = 0;
	}

	/**
	 * Add a packet to the end of the chat queue with priority $priority
	 */
	public function push(int $priority, AOChatPacket $item): void {
		if (isset($this->queue[$priority])) {
			$this->queue[$priority][] = $item;
		} else {
			$this->queue[$priority] = array($item);
			krsort($this->queue);
		}
		$this->qsize++;
	}

	/**
	 * Get the next packet to process
	 *
	 * Takes queue priorities into account
	 */
	public function getNext(): ?AOChatPacket {
		if ($this->qsize === 0) {
			return null;
		}
		$now = time();
		if ($this->point > $now) {
			return null;
		}

		foreach (array_keys($this->queue) as $priority) {
			while (true) {
				$item = array_shift($this->queue[$priority]);
				if ($item === null) {
					unset($this->queue[$priority]);
					break;
				}

				// $limit specifies how much buffer we have
				// this check makes sure we don't go beyond that buffer
				if ($this->point < ($now - $this->limit)) {
					$this->point = $now - $this->limit;
				}

				$this->point += $this->increment;
				$this->qsize--;
				return $item;
			}
		}
		return null;
	}
}
