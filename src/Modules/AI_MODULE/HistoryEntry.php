<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * A single entry in a conversation history, pairing an API message
 * with metadata.
 */
class HistoryEntry {
	/**
	 * @param \stdClass          $message   The API message payload.
	 * @param \DateTimeInterface $timestamp The creation timestamp.
	 */
	public function __construct(
		public readonly \stdClass $message,
		public readonly \DateTimeInterface $timestamp,
	) {
	}
}
