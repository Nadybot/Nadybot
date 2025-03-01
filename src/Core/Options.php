<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\Attributes\Hydrator\{MapValue, OptionFlag};

/** The command-line options of the bot */
class Options {
	use LoggableTrait;

	/**
	 * @param int         $verbosity            Verbosity level
	 * @param null|string $configFile           The config file to use, or `null` if unset
	 * @param bool        $help                 Whether to show the bot's help
	 * @param bool        $migrateOnly          Only run migrations, don't run the bot
	 * @param bool        $setupOnly            Only run setup procedures, don't run the bot
	 * @param bool        $strict               Be strict about SQLite types,and use the
	 *                                          strict grammar
	 * @param null|string $logConfig            The logger configuration or `null` for default
	 * @param bool        $migrationErrorsFatal Be very strict about migrations, and die
	 *                                          if any fail
	 */
	public function __construct(
		#[
			MapValue(read: [self::class, 'fromMultiFlag'], write: [self::class, 'toMultiFlag']),
			MapFrom('v')
		] public readonly int $verbosity=0,
		#[MapFrom('c')] public readonly ?string $configFile=null,
		#[OptionFlag] public readonly bool $help=false,
		#[OptionFlag, MapFrom('migrate-only')] public readonly bool $migrateOnly=false,
		#[OptionFlag, MapFrom('setup-only')] public readonly bool $setupOnly=false,
		#[OptionFlag, MapFrom('vue-dev')] public readonly bool $vueDevMode=false,
		#[OptionFlag] public readonly bool $strict=false,
		#[MapFrom('log-config')] public readonly ?string $logConfig=null,
		#[OptionFlag, MapFrom('migration-errors-fatal')] public readonly bool $migrationErrorsFatal=false,
	) {
	}

	/**
	 * Parse a `getopt()` flag option into a level of 0 to 1, 2, or more
	 *
	 * @param bool|list<bool> $value
	 */
	public static function fromMultiFlag(bool|array $value): int {
		if (is_bool($value)) {
			return 1;
		}
		return count($value);
	}

	/**
	 * Parse a flag level into how `getopt()` would return this
	 *
	 * @return null|bool|list<bool> $value
	 */
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
