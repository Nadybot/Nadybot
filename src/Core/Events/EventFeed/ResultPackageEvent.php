<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\Events\EventFeedPackageEvent;
use Nadybot\Core\{Attributes as NCA, Highway};

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
