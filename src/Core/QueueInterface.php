<?php declare(strict_types=1);

namespace Nadybot\Core;

interface QueueInterface {
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
}
