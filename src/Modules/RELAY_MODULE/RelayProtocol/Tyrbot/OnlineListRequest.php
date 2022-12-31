<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class OnlineListRequest extends Packet {
	public function __construct(
		public string $type="online_list_request",
	) {
	}
}
