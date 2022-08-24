<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Spatie\DataTransferObject\DataTransferObject;

class ApiSpawnData extends DataTransferObject {
	public string $name;
	public int $last_spawn;
	public int $dimension;
}
