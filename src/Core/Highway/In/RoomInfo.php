<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class RoomInfo extends InPackage {
	/** Is this a read-only room? */
	public bool $readOnly;

	/**
	 * @param string                                  $type             The package type
	 * @param string                                  $room             The ID/name of the room
	 * @param null|bool                               $readOnlyOld      Is this a read-only room (highway 1.0)
	 * @param null|bool                               $readOnlyNew      Is this a read-only room (highway 1.1)
	 * @param string[]                                $users            A list of all the user UUIDs in this room
	 * @param null|string|int|bool|float|array<mixed> $extraInfo        Extra info for this room
	 * @param null|RateLimit                          $msgFreqRatelimit An optional message frequency limit for this room
	 * @param null|RateLimit                          $msgSizeRatelimit An optional message size limit for this room
	 *
	 * @psalm-param list<string> $users
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
