<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\DataTransferObject;

class Source extends DataTransferObject {
	public string $name;
	public ?string $label;
	public ?string $channel;
	public string $type;
	public int $server;
}
