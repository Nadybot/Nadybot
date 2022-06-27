<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Spatie\DataTransferObject\DataTransferObject;

class ApiGauntletBuff extends DataTransferObject {
	public string $faction;
	public int $expires;
	public int $dimension;
}
