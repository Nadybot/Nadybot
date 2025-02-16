<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use AO\Client\WorkerPackage;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Types\EventInterface;

#[NCA\Event(mask: 'packet(*)')]
class PackageEvent implements EventInterface {
	public function __construct(
		public WorkerPackage $packet
	) {
	}

	public function getEvent(): string {
		$value = $this->packet->package->type->value ?? '*';
		return "packet({$value})";
	}
}
