<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\Events\EventFeedPackageEvent;
use Nadybot\Core\{Attributes as NCA, Highway};

/** The leave package is received when leaving a room */
#[NCA\Event(mask: 'event-feed(leave)')]
class LeavePackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Leave $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Leave {
		return $this->package;
	}
}
