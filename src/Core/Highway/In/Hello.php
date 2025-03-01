<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

/** The greeting package of the highway server gives  us a list of rooms, plus the config */
class Hello extends InPackage {
	/**
	 * The public rooms available on this server
	 *
	 * @var list<string>
	 */
	public array $publicRooms = [];

	/**
	 * @param null|string[] $publicRoomsOld A list of public rooms if this is a highway 1.0 server
	 * @param null|string[] $publicRoomsNew A list of public rooms if this is a highway 1.1 server
	 * @param Config        $config         This highway server's configuration of limits
	 *
	 * @psalm-param ?list<string> $publicRoomsOld
	 * @psalm-param ?list<string> $publicRoomsNew
	 */
	public function __construct(
		string $type,
		#[CastListToType('string')] #[MapFrom('public-rooms')] ?array $publicRoomsOld,
		#[CastListToType('string')] #[MapFrom('public_rooms')] ?array $publicRoomsNew,
		public Config $config,
	) {
		parent::__construct($type);
		$this->publicRooms = $publicRoomsNew ?? $publicRoomsOld ?? [];
	}
}
