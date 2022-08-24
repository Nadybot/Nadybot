<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

class AMQPExchange {
	public string $name;
	public string $type;

	/** @var string[] */
	public array $routingKeys = [];
}
