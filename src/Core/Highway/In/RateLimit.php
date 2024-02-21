<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

class RateLimit {
	public function __construct(
		public int $maxTokens,
		public int $tokens,
		public int $refillAmount,
		public int $refillMillis,
	) {
	}
}
