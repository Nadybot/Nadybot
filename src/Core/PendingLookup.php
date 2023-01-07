<?php declare(strict_types=1);

namespace Nadybot\Core;

class PendingLookup {
	public function __construct(
		public int $time,
		/** @var array{0: \Amp\Deferred<?int>|callable, 1: null|mixed[]}[] */
		public array $callbacks=[],
	) {
	}
}
