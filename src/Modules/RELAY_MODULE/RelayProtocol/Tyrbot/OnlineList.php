<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class OnlineList extends Packet {
	/** @param OnlineBlock[] $online */
	public function __construct(
		public string $type,
		public array $online,
	) {
	}
}
