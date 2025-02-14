<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\Attributes\Hydrator\{MapValue, OptionFlag};

class Options {
	use LoggableTrait;

	public function __construct(
		#[
			MapValue(read: [self::class, 'fromMultiFlag'], write: [self::class, 'toMultiFlag']),
			MapFrom('v')
		] public readonly int $verbosity=0,
		#[MapFrom('c')] public readonly ?string $configFile=null,
		#[OptionFlag] public readonly bool $help=false,
		#[OptionFlag, MapFrom('migrate-only')] public readonly bool $migrateOnly=false,
		#[OptionFlag, MapFrom('setup-only')] public readonly bool $setupOnly=false,
		#[OptionFlag] public readonly bool $strict=false,
		#[MapFrom('log-config')] public readonly ?string $logConfig=null,
		#[OptionFlag, MapFrom('migration-errors-fatal')] public readonly bool $migrationErrorsFatal=false,
	) {
	}

	/** @param bool|list<bool> $value */
	public static function fromMultiFlag(bool|array $value): int {
		if (is_bool($value)) {
			return 1;
		}
		return count($value);
	}

	/** @return null|bool|list<bool> $value */
	public static function toMultiFlag(int $value): null|bool|array {
		if ($value === 0) {
			return null;
		}
		if ($value === 1) {
			return false;
		}
		return array_fill(0, $value, false);
	}
}
