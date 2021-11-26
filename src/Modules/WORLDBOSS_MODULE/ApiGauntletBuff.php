<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Spatie\DataTransferObject\FlexibleDataTransferObject;

class ApiGauntletBuff extends FlexibleDataTransferObject {
	public string $faction;
	public int $expires;
}
