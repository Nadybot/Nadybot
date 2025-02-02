<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Drill\{AbstractDrillPacket, DrillConnection};
use Nadybot\Core\Safe;

class DrillPacketEvent extends DrillEvent {
	public const EVENT_MASK = 'drill(*)';

	public function __construct(
		DrillConnection $connection,
		public AbstractDrillPacket $packet,
	) {
		parent::__construct(connection: $connection);
		$kebabCase = Safe::pregReplace(
			'/([a-z])([A-Z])/',
			'$1-$2',
			class_basename($packet)
		);
		$this->type = 'drill(' . strtolower($kebabCase) . ')';
	}
}
