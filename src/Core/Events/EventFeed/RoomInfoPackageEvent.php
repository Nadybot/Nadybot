<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\{Attributes as NCA, Highway};
use Nadybot\Core\Events\EventFeedPackageEvent;

/** This event is fired upon entering a room, giving information about it */
#[NCA\Event(mask: 'event-feed(room-info)')]
class RoomInfoPackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\RoomInfo $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\RoomInfo {
		return $this->package;
	}
}
