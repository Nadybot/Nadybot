<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes\JSON;

class RelayLayerArgument extends DBRow {
	/**
	 * The id of the argument
	 */
	#[JSON\Ignore]
	public int $id;

	/**
	 * The id of the layer where this argument belongs to
	 */
	#[JSON\Ignore]
	public int $layer_id;

	/** The name of the argument */
	public string $name;

	/** The value of the argument */
	public string $value;

	public function toString(bool $isSecret): string {
		if ($isSecret) {
			return "{$this->name}=&lt;hidden&gt;";
		}
		if (preg_match("/^(true|false|\d+)$/", $this->value)) {
			return "{$this->name}={$this->value}";
		}
		return "{$this->name}=" . \Safe\json_encode($this->value, JSON_UNESCAPED_SLASHES);
	}
}
