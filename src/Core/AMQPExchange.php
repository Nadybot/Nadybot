<?php declare(strict_types=1);

namespace Nadybot\Core;

class AMQPExchange {
	public string $name;
	public string $type;
	/** @var string[] */
	public array $routingKeys = [];
}
