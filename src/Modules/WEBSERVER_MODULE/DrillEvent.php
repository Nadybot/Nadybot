<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Drill\DrillConnection;
use Nadybot\Core\Events\Event;

class DrillEvent extends Event {
	public const EVENT_MASK = 'drill(*)';

	public function __construct(
		public DrillConnection $connection,
	) {
		$this->type = self::EVENT_MASK;
	}
}
