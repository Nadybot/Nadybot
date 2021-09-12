<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\DataTransferObject;

class User extends DataTransferObject {
	public ?int $id;
	public string $name;
}
