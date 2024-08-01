<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\UuidInterface;

#[NCA\DB\Table(name: 'route_modifier')]
class RouteModifier extends DBTable {
	/**
	 * @param string                      $modifier  The name of the modifier
	 * @param ?int                        $id        The id of the route modifier. Lower id means higher priority
	 * @param ?UuidInterface              $route_id  The id of the route where this modifier belongs to
	 * @param list<RouteModifierArgument> $arguments
	 */
	public function __construct(
		public string $modifier,
		#[NCA\DB\AutoInc] public ?int $id=null,
		public ?UuidInterface $route_id=null,
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

	/** @return array<string,string|list<string>> */
	public function getKVArguments(): array {
		/** @var array<string,string|list<string>> */
		$result = array_reduce(
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
		return $result;
	}
}
