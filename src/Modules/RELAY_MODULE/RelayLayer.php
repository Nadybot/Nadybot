<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;

class RelayLayer extends DBRow {
	/** The id of the relay layer. Lower id means higher priority */
	public int $id;

	/** The id of the relay where this layer belongs to */
	public int $relay_id;

	/** Which relay stack layer does this represent? */
	public string $layer;

	/**
	 * @db:ignore
	 * @var RelayLayerArgument[]
	 */
	public array $arguments = [];

	public function toString(): string {
		$arguments = array_map(
			function(RelayLayerArgument $argument): string {
				return $argument->toString();
			},
			$this->arguments
		);
		return $this->layer . "(".
			join(", ", $arguments).
			")";
	}

	/**
	 * @return array<string,string>
	 */
	public function getKVArguments(): array {
		return array_reduce(
			$this->arguments,
			function(array $kv, RelayLayerArgument $argument): array {
				$kv[$argument->name] = $argument->value;
				return $kv;
			},
			[]
		);
	}
}
