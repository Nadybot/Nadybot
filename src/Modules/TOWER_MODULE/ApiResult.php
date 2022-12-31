<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

class ApiResult {
	/** @param ApiSite[] $results */
	public function __construct(
		public int $count,
		public array $results=[],
	) {
	}
}
