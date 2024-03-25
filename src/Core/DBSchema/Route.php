<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Route extends DBRow {
	/**
	 * @param string          $source         The source channel for this route
	 * @param string          $destination    The destination channel for this route
	 * @param bool            $two_way        Set to true if this route is also the other way around
	 * @param ?int            $disabled_until If set, the route is disabled until the set timestamp
	 * @param ?int            $id             The unique ID of this route
	 * @param RouteModifier[] $modifiers      The modifiers for this route
	 */
	public function __construct(
		public string $source,
		public string $destination,
		public bool $two_way=false,
		public ?int $disabled_until=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
		#[NCA\DB\Ignore] public array $modifiers=[],
	) {
	}
}
