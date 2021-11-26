<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Spatie\DataTransferObject\FlexibleDataTransferObject;

class ApiSpawnData extends FlexibleDataTransferObject {
	public string $name;
	public int $last_spawn;
}
