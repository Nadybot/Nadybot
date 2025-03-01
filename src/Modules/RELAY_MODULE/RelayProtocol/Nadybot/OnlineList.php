<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class OnlineList {
	public string $type = 'online_list';

	/** @param list<OnlineBlock> $online */
	public function __construct(
		#[CastListToType(OnlineBlock::class)] public array $online=[],
	) {
	}
}
