<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Hello extends Package {
	/** @param string[]  $publicRooms */
	public function __construct(
		#[CastListToType('string')] #[MapFrom("public-rooms")] public ?array $publicRoomsOld,
		#[CastListToType('string')] #[MapFrom("public_rooms")] public ?array $publicRooms,
		public Config $config,
	) {
		if (is_array($this->publicRoomsOld) && count($this->publicRoomsOld)) {
			$this->publicRooms = $this->publicRoomsOld;
		}
		$this->type = self::HELLO;
	}
}
