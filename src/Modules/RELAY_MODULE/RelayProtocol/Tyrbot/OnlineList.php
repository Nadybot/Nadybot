<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class OnlineList extends Packet {
	/**
	 * @param OnlineBlock[] $online
	 *
	 * @psalm-param list<OnlineBlock> $online
	 */
	public function __construct(
		#[CastListToType(OnlineBlock::class)] public array $online,
	) {
		parent::__construct(type: BasePacket::ONLINE_LIST);
	}
}
