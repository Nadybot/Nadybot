<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Client\WorkerPackage;

class PacketEvent extends Event {
	public WorkerPackage $packet;
}
