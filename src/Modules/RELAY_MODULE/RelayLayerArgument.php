<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Safe\{json_encode, preg_match};
use Nadybot\Core\Attributes\JSON;

use Nadybot\Core\DBRow;

class RelayLayerArgument extends DBRow {
	/** The id of the argument */
	#[JSON\Ignore]
	public int $id;

	/** The id of the layer where this argument belongs to */
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
		return "{$this->name}=" . json_encode($this->value, JSON_UNESCAPED_SLASHES);
	}
}
