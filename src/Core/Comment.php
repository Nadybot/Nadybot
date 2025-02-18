<?php declare(strict_types=1);

namespace Nadybot\Core;

class Comment {
	public function __construct(
		public readonly string $headline,
		public readonly ?string $description=null,
	) {
	}
}
