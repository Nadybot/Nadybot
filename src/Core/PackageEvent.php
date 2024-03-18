<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Client\WorkerPackage;

class PackageEvent extends Event {
	public const EVENT_MASK = "packet(*)";

	public function __construct(
		public WorkerPackage $packet
	) {
		$this->type = "packet(" . $packet->package->type->value . ")";
	}
}
