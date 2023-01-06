<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class ApiResult {
	/** @param ApiSite[] $results */
	public function __construct(
		public int $count,
		#[CastListToType(ApiSite::class)]
		public array $results=[],
	) {
	}
}
