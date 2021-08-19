<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RouteModifier extends DBRow {
	/** The id of the route modifier. Lower id means higher priority */
	public int $id;

	/** The id of the route where this modifier belongs to */
	public int $route_id;

	/** The name of the modifier */
	public string $modifier;

	/**
	 * @db:ignore
	 * @var RouteModifierArgument[]
	 */
	public array $arguments = [];

	public function toString(): string {
		$arguments = array_map(
			function(RouteModifierArgument $argument): string {
				return $argument->toString();
			},
			$this->arguments
		);
		return $this->modifier . "(".
			join(", ", $arguments).
			")";
	}

	/**
	 * @return array<string,string>
	 */
	public function getKVArguments(): array {
		return array_reduce(
			$this->arguments,
			function(array $kv, RouteModifierArgument $argument): array {
				if (isset($kv[$argument->name])) {
					if (is_array($kv[$argument->name])) {
						$kv[$argument->name] []= $argument->value;
					} else {
						$kv[$argument->name] = [$kv[$argument->name], $argument->value];
					}
				} else {
					$kv[$argument->name] = $argument->value;
				}
				return $kv;
			},
			[]
		);
	}
}
