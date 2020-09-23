<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS;

use Nadybot\Core\Event;

class AltEvent extends Event {
	public string $main;
	public string $alt;
	public ?bool $validated;
}
