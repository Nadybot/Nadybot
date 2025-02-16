<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Drill\DrillConnection;

#[Event(mask: 'drill(*)')]
abstract class DrillEvent {
	public function __construct(
		public DrillConnection $connection,
	) {
	}
}
