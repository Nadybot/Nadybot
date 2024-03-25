<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use function Safe\{json_encode, preg_match};
use Nadybot\Core\{Attributes as NCA, DBRow};

class RouteModifierArgument extends DBRow {
	/**
	 * @param string $name              The name of the argument
	 * @param string $value             The value of the argument
	 * @param ?int   $route_modifier_id The id of the route modifier where this argument belongs to
	 * @param ?int   $id                The id of the argument
	 */
	public function __construct(
		public string $name,
		public string $value,
		public ?int $route_modifier_id=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}

	public function toString(): string {
		if (preg_match("/^(true|false|\d+)$/", $this->value)) {
			return "{$this->name}={$this->value}";
		}
		return "{$this->name}=" . json_encode($this->value, \JSON_UNESCAPED_SLASHES);
	}
}
