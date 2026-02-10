<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use function Safe\{json_decode, json_encode};

use Nadybot\Core\Attributes\DB\{MapRead, MapWrite, PK, Shared, Table};
use Nadybot\Core\{DBTable, Hydrator};

#[Table(name: 'implant_design', shared: Shared::Yes)]
class ImplantDesign extends DBTable {
	public int $dt;

	public function __construct(
		#[PK] public string $name,
		#[PK] public string $owner,
		?int $dt=null,
		#[
			MapRead([self::class, 'decodeDesign']),
			MapWrite([self::class, 'encodeDesign']),
		] public ?ImplantConfig $design=null,
	) {
		$this->dt = $dt ?? time();
	}

	public static function decodeDesign(?string $design): ?ImplantConfig {
		if (!isset($design) || $design === 'null') {
			return null;
		}

		/** @var array<string,mixed> */
		$json = json_decode($design, true);
		return Hydrator::hydrate(ImplantConfig::class, $json);
	}

	public static function encodeDesign(?ImplantConfig $design): ?string {
		if (!isset($design)) {
			return null;
		}

		/** @var array<string,mixed> */
		$mapped = Hydrator::serialize($design);
		foreach ($mapped as $key => $value) {
			if ($value === null) {
				unset($mapped[$key]);
			} elseif (is_array($value)) {
				foreach ($value as $subkey => $subvalue) {
					if ($subvalue === null) {
						// @mago-ignore analysis:mixed-array-access
						unset($mapped[$key][$subkey]);
					}
				}
			}
		}
		return json_encode($mapped);
	}
}
