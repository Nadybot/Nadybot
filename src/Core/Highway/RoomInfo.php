<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class RoomInfo extends Package {
	public bool $readOnly;
	/**
	 * @param string[]                                $users
	 * @param null|string|int|bool|float|array<mixed> $extraInfo
	 */
	public function __construct(
		public string $room,
		#[MapFrom('read-only')] ?bool $readOnlyOld,
		#[MapFrom('read_only')] ?bool $readOnlyNew,
		#[CastListToType('string')] public array $users,
		public null|string|int|bool|float|array $extraInfo=null,
		public ?RateLimit $msgFreqRatelimit=null,
		public ?RateLimit $msgSizeRatelimit=null,
	) {
		$this->readOnly = $readOnlyNew ?? $readOnlyOld ?? false;
		$this->type = self::ROOM_INFO;
	}
}
