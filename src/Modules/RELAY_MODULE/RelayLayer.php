<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;

class RelayLayer extends DBRow {
	/**
	 * The id of the relay layer. Lower id means higher priority
	 * @json:ignore
	 */
	public int $id;

	/**
	 * The id of the relay where this layer belongs to
	 * @json:ignore
	 */
	public int $relay_id;

	/** Which relay stack layer does this represent? */
	public string $layer;

	/**
	 * @var RelayLayerArgument[]
	 */
	#[NCA\DB\Ignore]
	public array $arguments = [];

	public function toString(?string $linkType=null, array $secrets=[]): string {
		$arguments = array_map(
			function(RelayLayerArgument $argument) use ($secrets): string {
				return $argument->toString(in_array($argument->name, $secrets));
			},
			$this->arguments
		);
		$argString = "(" . join(", ", $arguments) . ")";
		if (!isset($linkType)) {
			return $this->layer . $argString;
		}
		return "<a href='chatcmd:///tell <myname> relay list {$linkType} {$this->layer}'>".
			$this->layer . "</a>{$argString}";
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
