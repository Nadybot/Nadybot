<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class RouteModifier extends DBRow {
	/**
	 * @param string                  $modifier  The name of the modifier
	 * @param ?int                    $id        The id of the route modifier. Lower id means higher priority
	 * @param ?int                    $route_id  The id of the route where this modifier belongs to
	 * @param RouteModifierArgument[] $arguments
	 */
	public function __construct(
		public string $modifier,
		#[NCA\DB\AutoInc] public ?int $id=null,
		public ?int $route_id=null,
		#[NCA\DB\Ignore] public array $arguments=[],
	) {
	}

	public function toString(bool $asLink=false): string {
		$arguments = array_map(
			static function (RouteModifierArgument $argument): string {
				return $argument->toString();
			},
			$this->arguments
		);
		if ($asLink) {
			$arguments = array_map('htmlspecialchars', $arguments);
		}
		$modName = $this->modifier;
		if ($asLink) {
			$modName = "<a href='chatcmd:///tell <myname> route list mod {$modName}'>{$modName}</a>";
		}
		return $modName . '(' . implode(', ', $arguments) . ')';
	}

	/** @return array<string,string|string[]> */
	public function getKVArguments(): array {
		return array_reduce(
			$this->arguments,
			static function (array $kv, RouteModifierArgument $argument): array {
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
