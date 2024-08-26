<?php declare(strict_types=1);

namespace Nadybot\Core;

class Comment {
	public function __construct(
		public string $headline,
		public ?string $description=null,
	) {
	}
}
