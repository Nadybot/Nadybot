<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\{Attributes as NCA, Highway, StringableTrait};
use Stringable;

/**
 * This is an abstratc class for all event-feed packages
 * All of them must supply a highway connection which can
 * be used to send replies on.
 */
#[NCA\Event(mask: 'event-feed(*)')]
abstract class EventFeedPackageEvent implements Stringable {
	use StringableTrait;

	/**
	 * @param Highway\Connection $connection The highway connection via which the
	 *                                       package was received, and via which replies
	 *                                       can be sent.
	 */
	public function __construct(
		private Highway\Connection $connection,
	) {
	}

	public function getConnection(): Highway\Connection {
		return $this->connection;
	}

	abstract public function getPackage(): Highway\In\InPackage;
}
