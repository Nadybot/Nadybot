<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

class ArbiterEvent {
	public int $start;

	public int $end;

	public string $shortName;

	public string $longName;

	/** Check if this event is active during the given timestamp */
	public function isActiveOn(int $time): bool {
		return $this->start <= $time && $this->end > $time;
	}
}
