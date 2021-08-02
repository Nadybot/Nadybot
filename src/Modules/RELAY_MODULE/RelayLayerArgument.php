<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;

class RelayLayerArgument extends DBRow {
	/** The id of the argument */
	public int $id;

	/** The id of the layer where this argument belongs to */
	public int $layer_id;

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
