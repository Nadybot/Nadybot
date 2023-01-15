<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Promise;

interface EventFeedHandler {
	/**
	 * @param array<string,mixed> $data
	 *
	 * @return Promise<void>
	 */
	public function handleEventFeedMessage(string $room, array $data): Promise;
}
