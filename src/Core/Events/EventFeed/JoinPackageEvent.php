<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\Events\EventFeedPackageEvent;
use Nadybot\Core\{Attributes as NCA, Highway};

/** The join package is received when joining a room */
#[NCA\Event(mask: 'event-feed(join)')]
class JoinPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Join $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Join {
		return $this->package;
	}
}
