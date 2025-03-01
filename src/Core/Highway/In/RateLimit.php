<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

/** A rate limit for messages or bytes defined by a token bucket */
class RateLimit {
	/**
	 * @param int $maxTokens    How many tokens can fit into the bucket?
	 * @param int $tokens       How many tokens are in the bucket initially?
	 * @param int $refillAmount How many tokens are refilled on every tick?
	 * @param int $refillMillis How many milliseconds between two ticks?
	 */
	public function __construct(
		public int $maxTokens,
		public int $tokens,
		public int $refillAmount,
		public int $refillMillis,
	) {
	}
}
