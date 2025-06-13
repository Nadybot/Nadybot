<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\{Attributes as NCA, Highway};
use Nadybot\Core\Events\EventFeedPackageEvent;

/** The welcome package is received directly after connecting to the feed */
#[NCA\Event(mask: 'event-feed(hello)')]
class HelloPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Hello $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Hello {
		return $this->package;
	}
}
