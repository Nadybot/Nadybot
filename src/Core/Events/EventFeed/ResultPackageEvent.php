<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\{Attributes as NCA, Highway};
use Nadybot\Core\Events\EventFeedPackageEvent;

/** This event is an abstract representation of success or failure of a command */
#[NCA\Event(mask: 'event-feed(result)')]
class ResultPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Result $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Result {
		return $this->package;
	}
}
