<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\Events\EventFeedPackageEvent;
use Nadybot\Core\{Attributes as NCA, Highway};

/** An error from the highway server */
#[NCA\Event(mask: 'event-feed(error)')]
class ErrorPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Error $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Error {
		return $this->package;
	}
}
