<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Drill\{AbstractDrillPacket, DrillConnection};
use Nadybot\Core\Safe;
use Nadybot\Core\Types\EventInterface;

#[Event(mask: 'drill(*)')]
class DrillPacketEvent extends DrillEvent implements EventInterface {
	private string $kebabCase;

	public function __construct(
		DrillConnection $connection,
		public AbstractDrillPacket $packet,
	) {
		parent::__construct(connection: $connection);
		$this->kebabCase = strtolower(
			Safe::pregReplace(
				'/([a-z])([A-Z])/',
				'$1-$2',
				class_basename($packet)
			)
		);
	}

	public function getEvent(): string {
		return "drill({$this->kebabCase})";
	}
}
