<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

class RelayMessage {
	public ?string $sender = null;

	/** @var string[] */
	public array $packages = [];
}
