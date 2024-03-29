<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class Hello extends InPackage {
	/** @var string[] */
	public array $publicRooms = [];

	/**
	 * @param null|string[] $publicRoomsOld
	 * @param null|string[] $publicRoomsNew
	 */
	public function __construct(
		string $type,
		#[CastListToType('string')] #[MapFrom("public-rooms")] ?array $publicRoomsOld,
		#[CastListToType('string')] #[MapFrom("public_rooms")] ?array $publicRoomsNew,
		public Config $config,
	) {
		parent::__construct($type);
		$this->publicRooms = $publicRoomsNew ?? $publicRoomsOld ?? [];
	}
}
