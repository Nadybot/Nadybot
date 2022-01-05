<?php declare(strict_types=1);

namespace Nadybot\Core;

interface QueueInterface {
	public const PRIORITY_HIGH = 1000;
	public const PRIORITY_MED =   500;
	public const PRIORITY_LOW =   100;

	/**
	 * Add a packet to the queue
	 */
	public function push(int $priority, AOChatPacket $item): void;

	/**
	 * Get the next packet to process
	 *
	 * Takes queue priorities into account
	 */
	public function getNext(): ?AOChatPacket;

	public function disable(): void;

	/**
	 * Clear all items from the queue and return the number of removed items
	 */
	public function clear(): int;

	/**
	 * Returns the number of items currently in the queue
	 */
	public function getSize(): int;
}
