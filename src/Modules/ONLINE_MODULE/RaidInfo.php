<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

class RaidInfo {
	public function __construct(
		public string $pre='',
		public string $post='',
	) {
	}
}
