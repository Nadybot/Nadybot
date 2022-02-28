<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;

class RouteModifier extends DBRow {
	/** The id of the route modifier. Lower id means higher priority */
	public int $id;

	/** The id of the route where this modifier belongs to */
	public int $route_id;

	/** The name of the modifier */
	public string $modifier;

	/**
	 * @var RouteModifierArgument[]
	 */
	#[NCA\DB\Ignore]
	public array $arguments = [];

	public function toString(bool $asLink=false): string {
		$arguments = array_map(
			function(RouteModifierArgument $argument): string {
				return $argument->toString();
			},
			$this->arguments
		);
		if ($asLink) {
			$arguments = array_map("htmlspecialchars", $arguments);
		}
		$modName = $this->modifier;
		if ($asLink) {
			$modName = "<a href='chatcmd:///tell <myname> route list mod {$modName}'>{$modName}</a>";
		}
		return $modName . "(" . join(", ", $arguments) . ")";
	}

	/**
	 * @return array<string,string|string[]>
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
