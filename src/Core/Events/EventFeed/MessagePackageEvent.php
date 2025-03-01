<?php declare(strict_types=1);

namespace Nadybot\Core\Events\EventFeed;

use Nadybot\Core\Events\EventFeedPackageEvent;
use Nadybot\Core\{Attributes as NCA, Highway};

/** This event is fired when receive a highway message from the event feed */
#[NCA\Event(mask: 'event-feed(message)')]
class MessagePackageEvent extends EventFeedPackageEvent {
	public function __construct(
		Highway\Connection $connection,
		public readonly Highway\In\Message $package,
	) {
		parent::__construct(connection: $connection);
	}

	public function getPackage(): Highway\In\Message {
		return $this->package;
	}
}
