<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Drill\DrillConnection;

class DrillConnectEvent extends DrillEvent {
	public const EVENT_MASK = 'drill(connect)';

	public function __construct(
		public DrillConnection $connection,
	) {
		$this->type = self::EVENT_MASK;
	}
}
