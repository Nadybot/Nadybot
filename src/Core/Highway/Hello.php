<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Hello extends Package {
	/** @param string[]  $publicRooms */
	public function __construct(
		#[CastListToType('string')] #[MapFrom("public-rooms")] public array $publicRooms,
		public Config $config,
	) {
		$this->type = self::HELLO;
	}
}
