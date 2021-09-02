<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\DataTransferObject;

class Packet extends DataTransferObject {
	public string $type;
}
