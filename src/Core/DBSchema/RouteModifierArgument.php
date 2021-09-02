<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RouteModifierArgument extends DBRow {
	/** The id of the argument */
	public int $id;

	/** The id of the route modifier where this argument belongs to */
	public int $route_modifier_id;

	/** The name of the argument */
	public string $name;

	/** The value of the argument */
	public string $value;

	public function toString(): string {
		if (preg_match("/^(true|false|\d+)$/", $this->value)) {
			return "{$this->name}={$this->value}";
		}
		return "{$this->name}=" . json_encode($this->value, JSON_UNESCAPED_SLASHES);
	}
}
