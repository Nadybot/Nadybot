<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This interface will handle event feed messages */
interface EventFeedHandler {
	/**
	 * Handle an event feed message for the given room
	 *
	 * @param string              $room The Highway room where the event occurred
	 * @param array<string,mixed> $data The decoded JSON data of the event as an
	 *                                  associative array
	 */
	public function handleEventFeedMessage(string $room, array $data): void;
}
