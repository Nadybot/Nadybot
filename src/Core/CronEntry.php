<?php declare(strict_types=1);

namespace Nadybot\Core;

class CronEntry {
	public ?string $moveHandle = null;

	public function __construct(
		public int $time,
		public string $filename,
		public int $nextevent,
		public ?string $handle=null
	) {
	}
}
