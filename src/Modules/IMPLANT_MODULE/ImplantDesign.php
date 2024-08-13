<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use function Safe\{json_decode, json_encode};

use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Nadybot\Core\Attributes\DB\{MapRead, MapWrite, PK, Shared, Table};
use Nadybot\Core\{DBTable};

#[Table(name: 'implant_design', shared: Shared::Yes)]
class ImplantDesign extends DBTable {
	public function __construct(
		#[PK] public string $name,
		#[PK] public string $owner,
		public ?int $dt=null,
		#[
			MapRead([self::class, 'decodeDesign']),
			MapWrite([self::class, 'encodeDesign']),
		] public ?ImplantConfig $design=null,
	) {
		$this->dt ??= time();
	}

	public static function decodeDesign(?string $design): ?ImplantConfig {
		if (!isset($design)) {
			return null;
		}
		$mapper = new ObjectMapperUsingReflection();
		return $mapper->hydrateObject(ImplantConfig::class, json_decode($design, true));
	}

	public static function encodeDesign(?object $design): ?string {
		if (!isset($design)) {
			return null;
		}
		$mapper = new ObjectMapperUsingReflection();
		$mapped = $mapper->serializeObject($design);
		foreach ($mapped as $key => $value) {
			if ($value === null) {
				unset($mapped[$key]);
			} elseif (is_array($value)) {
				foreach ($value as $subkey => $subvalue) {
					if ($subvalue === null) {
						unset($mapped[$key][$subkey]);
					}
				}
			}
		}
		return json_encode($mapped);
	}
}
