<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Client\WorkerPackage;

class PackageEvent extends Event {
	public WorkerPackage $packet;
}
