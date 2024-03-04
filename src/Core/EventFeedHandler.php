<?php declare(strict_types=1);

namespace Nadybot\Core;

interface EventFeedHandler {
	/** @param array<string,mixed> $data */
	public function handleEventFeedMessage(string $room, array $data): void;
}
