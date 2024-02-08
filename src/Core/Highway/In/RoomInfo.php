<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class RoomInfo extends InPackage {
	public bool $readOnly;

	/**
	 * @param string[]                                $users
	 * @param null|string|int|bool|float|array<mixed> $extraInfo
	 */
	public function __construct(
		string $type,
		public string $room,
		#[MapFrom('read-only')] ?bool $readOnlyOld,
		#[MapFrom('read_only')] ?bool $readOnlyNew,
		#[CastListToType('string')] public array $users,
		public null|string|int|bool|float|array $extraInfo=null,
		public ?RateLimit $msgFreqRatelimit=null,
		public ?RateLimit $msgSizeRatelimit=null,
	) {
		parent::__construct($type);
		$this->readOnly = $readOnlyNew ?? $readOnlyOld ?? false;
	}
}
