<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Types\EventInterface;
use Nadybot\Core\{Attributes as NCA, Highway, StringableTrait};
use Stringable;

#[NCA\Event(mask: 'event-feed(*)')]
class LowLevelEventFeedEvent implements EventInterface, Stringable {
	use StringableTrait;

	public function __construct(
		public Highway\Connection $connection,
		public Highway\In\InPackage $highwayPackage,
	) {
	}

	public function getEvent(): string {
		return "event-feed({$this->highwayPackage->type})";
	}
}
