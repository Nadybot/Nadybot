<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\{Attributes as NCA, Highway};
use Nadybot\Core\Events\EventFeedPackageEvent;

/** A success message from the highway server */
#[NCA\Event(mask: 'event-feed(success)')]
class SuccessPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Success $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Success {
		return $this->package;
	}
}
