<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\{Attributes as NCA, Highway, StringableTrait};
use Stringable;

#[NCA\Event(mask: 'event-feed(*)')]
abstract class EventFeedPackageEvent implements Stringable {
	use StringableTrait;

	public function __construct(
		private Highway\Connection $connection,
	) {
	}

	public function getConnection(): Highway\Connection {
		return $this->connection;
	}

	abstract public function getPackage(): Highway\In\InPackage;
}
