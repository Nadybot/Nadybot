<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Spatie\DataTransferObject\FlexibleDataTransferObject;

class ApiResult extends FlexibleDataTransferObject {
	public int $count;
	/** @var \Nadybot\Modules\TOWER_MODULE\ApiSite[] */
	public array $results = [];
}
