<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Logon extends Packet {
	public function __construct(
		public string $type,
		public User $user,
		public Source $source,
	) {
	}
}
