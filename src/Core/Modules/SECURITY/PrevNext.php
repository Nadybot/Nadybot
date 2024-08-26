<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SECURITY;

class PrevNext {
	public function __construct(
		public ?string $prev=null,
		public ?string $next=null,
	) {
	}
}
