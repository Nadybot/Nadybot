<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Routing\Source;

class OnlineBlock {
	/**
	 * @param list<Source>         $path
	 * @param list<RelayCharacter> $users
	 */
	public function __construct(
		#[CastListToType(Source::class)] public array $path=[],
		#[CastListToType(RelayCharacter::class)] public array $users=[],
	) {
	}
}
