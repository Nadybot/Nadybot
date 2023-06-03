<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class RoomInfo extends Package {
	/**
	 * @param string[]                                $users
	 * @param null|string|int|bool|float|array<mixed> $extraInfo
	 */
	public function __construct(
		public string $room,
		#[MapFrom('read-only')] public bool $readOnly,
		#[CastListToType('string')] public array $users,
		#[MapFrom('extra-info')] public null|string|int|bool|float|array $extraInfo=null,
	) {
		$this->type = self::ROOM_INFO;
	}
}
