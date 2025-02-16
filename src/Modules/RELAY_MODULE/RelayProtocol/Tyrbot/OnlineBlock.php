<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class OnlineBlock {
	/** @param list<User> $users */
	public function __construct(
		public Source $source,
		#[CastListToType(User::class)] public array $users,
	) {
	}
}
