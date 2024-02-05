<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Hello extends Package {
	/** @var string[] */
	public array $publicRooms = [];

	/**
	 * @param null|string[] $publicRoomsOld
	 * @param null|string[] $publicRoomsNew
	 */
	public function __construct(
		#[CastListToType('string')] #[MapFrom("public-rooms")] public ?array $publicRoomsOld,
		#[CastListToType('string')] #[MapFrom("public_rooms")] public ?array $publicRoomsNew,
		public Config $config,
	) {
		$this->publicRooms = $this->publicRoomsNew ?? $this->publicRoomsOld ?? [];
		$this->type = self::HELLO;
	}
}
