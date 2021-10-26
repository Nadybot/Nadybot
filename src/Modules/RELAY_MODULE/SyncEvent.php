<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\Event;

class SyncEvent extends Event {
	public string $sourceBot;
	public int $sourceDimention;
}
