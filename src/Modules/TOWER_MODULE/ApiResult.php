<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;
use Spatie\DataTransferObject\DataTransferObject;

class ApiResult extends DataTransferObject {
	public int $count;

	/** @var ApiSite[] */
	#[CastWith(ArrayCaster::class, itemType: ApiSite::class)]
	public array $results = [];
}
