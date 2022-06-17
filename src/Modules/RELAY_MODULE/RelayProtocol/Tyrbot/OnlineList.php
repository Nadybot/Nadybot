<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;

class OnlineList extends Packet {
	public string $type = "online_list";

	/** @var OnlineBlock[] */
	#[CastWith(ArrayCaster::class, itemType: OnlineBlock::class)]
	public array $online;
}
