<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'route')]
class Route extends DBTable {
	/** The unique ID of this route */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string              $source         The source channel for this route
	 * @param string              $destination    The destination channel for this route
	 * @param bool                $two_way        Set to true if this route is also the other way around
	 * @param ?int                $disabled_until If set, the route is disabled until the set timestamp
	 * @param ?UuidInterface      $id             The unique ID of this route (if known)
	 * @param list<RouteModifier> $modifiers      The modifiers for this route
	 */
	public function __construct(
		public string $source,
		public string $destination,
		public bool $two_way=false,
		public ?int $disabled_until=null,
		?UuidInterface $id=null,
		#[NCA\DB\Ignore] public array $modifiers=[],
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
