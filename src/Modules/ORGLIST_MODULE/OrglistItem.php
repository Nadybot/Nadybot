<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

class OrglistItem {
	public function __construct(
		public readonly string $name,
		public readonly bool $online,
	) {
	}
}
