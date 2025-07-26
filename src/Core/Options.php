<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\MapFrom;
use Nadybot\Core\Attributes\Hydrator\{MapValue, OptionFlag};

/** The command-line options of the bot */
class Options {
	use LoggableTrait;

	/**
	 * @param int           $verbosity            Verbosity level
	 * @param null|string   $configFile           The config file to use, or `null` if unset
	 * @param bool          $help                 Whether to show the bot's help
	 * @param bool          $migrateOnly          Only run migrations, don't run the bot
	 * @param bool          $setupOnly            Only run setup procedures, don't run the bot
	 * @param bool          $vueDevMode           Expect hot-loading vale instances to serve
	 *                                            the web interfaces
	 * @param bool          $testRun              After becoming ready, only run tests
	 *                                            and exit again
	 * @param bool          $testShowErrorsOnly   During --test-run only display errors
	 * @param ?list<string> $testFiles            If set, only run these given tests
	 *                                            strict grammar
	 * @param bool          $strict               Be strict about SQLite types,and use the
	 * @param null|string   $logConfig            The logger configuration or `null` for default
	 * @param bool          $migrationErrorsFatal Be very strict about migrations, and die
	 *                                            if any fail
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
		#[OptionFlag, MapFrom('test-run')] public readonly bool $testRun=false,
		#[OptionFlag, MapFrom('test-show-errors-only')] public readonly bool $testShowErrorsOnly=false,
		#[
			MapValue(read: [self::class, 'fromMultiString'], write: [self::class, 'toMultiString']),
			MapFrom('test-file')
		] public readonly ?array $testFiles=null,
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

	/**
	 * Parse a `getopt()` string option that allows multiple values
	 *
	 * @param null|string|non-empty-list<string> $value
	 *
	 * @return null|non-empty-list<string>
	 */
	public static function fromMultiString(null|string|array $value): ?array {
		if (!isset($value)) {
			return null;
		}
		if (is_string($value)) {
			return [$value];
		}
		return $value;
	}

	/**
	 * Parse a string option into how `getopt()` would return this
	 *
	 * @param null|non-empty-list<string> $value
	 *
	 * @return null|string|list<string> $value
	 */
	public static function toMultiString(?array $value): null|string|array {
		if (!isset($value)) {
			return null;
		}
		if (count($value) === 1) {
			return $value[0];
		}
		return $value;
	}
}
