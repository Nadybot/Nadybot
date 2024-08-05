<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Safe\{json_encode, preg_match};

use Nadybot\Core\Attributes\{DB, JSON};
use Nadybot\Core\DBTable;
use Ramsey\Uuid\{Uuid, UuidInterface};

#[DB\Table(name: 'relay_layer_argument')]
class RelayLayerArgument extends DBTable {
	/** The id of the argument */
	#[JSON\Ignore] #[DB\PK] public UuidInterface $id;

	/**
	 * @param string         $name     The name of the argument
	 * @param string         $value    The value of the argument
	 * @param UuidInterface  $layer_id The id of the layer where this argument belongs to
	 * @param ?UuidInterface $id       The id of the argument
	 */
	public function __construct(
		public string $name,
		public string $value,
		#[JSON\Ignore] public UuidInterface $layer_id,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}

	public function toString(bool $isSecret): string {
		if ($isSecret) {
			return "{$this->name}=&lt;hidden&gt;";
		}
		if (preg_match("/^(true|false|\d+)$/", $this->value)) {
			return "{$this->name}={$this->value}";
		}
		return "{$this->name}=" . json_encode($this->value, \JSON_UNESCAPED_SLASHES);
	}
}
