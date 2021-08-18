<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class OnlineList extends Packet {
	public string $type = "online_list";
	/** @var \Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot\OnlineBlock[] */
	public array $online;
}
