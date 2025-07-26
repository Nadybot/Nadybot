<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use function Safe\json_encode;
use Nadybot\Core\{Attributes as NCA, DBTable, Safe};
use Ramsey\Uuid\{Uuid, UuidInterface};

/** This table holds the parameters for each route modifier */
#[NCA\DB\Table(name: 'route_modifier_argument')]
class RouteModifierArgument extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $name              The name of the argument
	 * @param string         $value             The value of the argument
	 * @param UuidInterface  $route_modifier_id The id of the route modifier where this argument belongs to
	 * @param ?UuidInterface $id                The id of the argument
	 */
	public function __construct(
		public string $name,
		public string $value,
		public UuidInterface $route_modifier_id,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}

	public function toString(): string {
		if (Safe::pregMatches("/^(true|false|\d+)$/", $this->value)) {
			return "{$this->name}={$this->value}";
		}
		return "{$this->name}=" . json_encode($this->value, \JSON_UNESCAPED_SLASHES);
	}
}
