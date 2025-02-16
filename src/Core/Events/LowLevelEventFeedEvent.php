<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\{Attributes as NCA, Highway};

#[NCA\Event(mask: 'event-feed(*)')]
class LowLevelEventFeedEvent extends Event {
	public function __construct(
		string $type,
		public Highway\Connection $connection,
		public Highway\In\InPackage $highwayPackage,
	) {
		parent::__construct(type: $type);
	}
}
