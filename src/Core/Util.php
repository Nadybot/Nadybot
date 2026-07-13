<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{date, getcwd};
use Amp\File\FilesystemException;
use BackedEnum;
use Error;
use Exception;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	Types\ParamType,
};
use RangeException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;

/** Some utility functions in a static helper class */
#[NCA\Instance]
class Util {
	/**
	 * The date/time format to use when displaying date and time in the bot
	 *
	 * Looks like `07-Mar-2024 23:11:00`
	 *
	 * @var string
	 */
	public const DATETIME = 'd-M-Y H:i T';

	/**
	 * The date/time format to use when displaying a date in the bot
	 *
	 * Looks like `07-Mar-2024`
	 *
	 * @var string
	 */
	public const DATE = 'd-M-Y';

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	/** Convert bytes to kB, MB, etc. so it's never more than 1024 */
	public static function bytesConvert(int $bytes): string {
		$ext = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
		$unitCount = 0;
		for ($max = count($ext) - 1; $bytes >= 1_024 && $unitCount < $max; $unitCount++) {
			$bytes /= 1_024;
		}
		if (!isset($ext[$unitCount])) {
			throw new RangeException("{$bytes} too large to handle");
		}
		return round($bytes, 2) .' '. $ext[$unitCount];
	}

	/**
	 * Converts a duration in seconds into a human readable format
	 *
	 * Converts 3688 to "1hr, 1min, 18secs"
	 */
	public static function unixtimeToReadable(int $time, bool $showSeconds=true): string {
		if ($time === 0) {
			return '0 secs';
		}

		$units = [
			'year' => 31_536_000,
			'day' => 86_400,
			'hr' => 3_600,
			'min' => 60,
			'sec' => 1,
		];

		$timeshift = '';
		foreach ($units as $unit => $seconds) {
			if ($time > 0) {
				$length = (int)floor($time / $seconds);
			} else {
				$length = (int)ceil($time / $seconds);
			}
			if ($unit !== 'sec' || $showSeconds || $timeshift === '') {
				if ($length > 1) {
					$timeshift .= $length . ' ' . $unit . 's ';
				} elseif ($length === 1) {
					$timeshift .= $length . ' ' . $unit . ' ';
				}
			}
			$time = $time % $seconds;
		}

		return trim($timeshift);
	}

	/**
	 * Try to parse a duration into seconds
	 *
	 * Convert "1h, 2mins 10s" into 3730
	 *
	 * @param string $budatime A human readable duration
	 *
	 * @return int The duration in seconds, or `0` on error
	 */
	public static function parseTime(string $budatime): int {
		$unixtime = 0;

		$matches = [];
		$pattern = '/([0-9]+)([a-z]+)/';

		/** @var list<list{string,numeric-string,string}> */
		$matches = Safe::pregMatchOrderedAll($pattern, $budatime);

		foreach ($matches as $match) {
			$quantifier = match ($match[2]) {
				'y','yr','year','years' => 31_536_000,
				'mo','month','months' => 2_592_000,
				'weeks','week','w' => 604_800,
				'days','day','d' => 86_400,
				'hours','hour','hrs','hr','h' => 3_600,
				'mins','min','m' => 60,
				'secs','sec','s' => 1,
				default => 0,
			};
			if ($quantifier === 0) {
				return 0;
			}
			$unixtime += (int)$match[1] * $quantifier;
		}

		return $unixtime;
	}

	/**
	 * Compares two version numbers
	 *
	 * @return int 1 if the first is greater than the second,
	 *             -1 if the second is greater than the first and
	 *             0 if they are equal.
	 */
	public static function compareVersionNumbers(string $ver1, string $ver2): int {
		$ver1Array = explode('.', $ver1);
		$ver2Array = explode('.', $ver2);

		for ($i = 0; $i < count($ver1Array) && $i < count($ver2Array); $i++) {
			if ($ver1Array[$i] > $ver2Array[$i]) {
				return 1;
			} elseif ($ver1Array[$i] < $ver2Array[$i]) {
				return -1;
			}
		}

		if (count($ver1Array) > count($ver2Array)) {
			return 1;
		} elseif (count($ver1Array) < count($ver2Array)) {
			return -1;
		}
		return 0;
	}

	/** Completes a filename or directory by searching for it in modules and core paths */
	public function verifyFilename(string $filename): string {
		// Replace all \ characters with /
		$filename = str_replace('\\', '/', $filename);

		// check if the file exists
		foreach (array_reverse($this->config->paths->modules) as $modulePath) {
			if ($this->fs->exists("{$modulePath}/{$filename}")) {
				return "{$modulePath}/{$filename}";
			}
		}
		if ($this->fs->exists(__DIR__ . "/{$filename}")) {
			return __DIR__ . "/{$filename}";
		}
		if ($this->fs->exists(__DIR__ . "/Modules/{$filename}")) {
			return __DIR__ . "/Modules/{$filename}";
		}
		if ($this->fs->exists($filename)) {
			return $filename;
		}
		return '';
	}

	/**
	 * Randomly get a value from an array
	 *
	 * @template T
	 *
	 * @param array<array-key,T> $array
	 *
	 * @return T
	 */
	public static function randomArrayValue(array $array): mixed {
		// @phpstan-ignore argument.type
		return $array[array_rand($array)];
	}

	/**
	 * Checks to see if the $sender is valid
	 *
	 * Invalid values: -1 on 32bit and 4294967295  on 64bit
	 */
	public static function isValidSender(int|string $sender): bool {
		$isValid = !in_array(
			$sender,
			[(string)0xFF_FF_FF_FF, 0xFF_FF_FF_FF, '-1', -1],
			true
		);
		return $isValid;
	}

	/**
	 * Create a random string of `$length` characters
	 *
	 * @param int              $length     The number of characters for the result
	 * @param non-empty-string $characters A string containing all allowed characters
	 *
	 * @return string A random string with `$length` characters
	 */
	public static function genRandomString(int $length=10, string $characters='0123456789abcdefghijklmnopqrstuvwxyz'): string {
		$string = '';
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[random_int(0, strlen($characters)-1)];
		}
		return $string;
	}

	/** Get a stack trace of the calling stack as a string */
	public static function getStackTrace(): string {
		$trace = debug_backtrace();
		$arr1 = [];
		$arr2 = [];
		foreach ($trace as $obj) {
			$file = str_replace(getcwd() . '/', '', $obj['file'] ?? '{Closure}');
			if (isset($obj['line'])) {
				$arr1 []= "{$file}({$obj['line']})";
			} else {
				$arr1 []= "{$file}";
			}
			$arr2 []= "{$obj['function']}()";
		}

		array_shift($arr2);

		$str = '';
		for ($i = 0; $i < count($arr1); $i++) {
			if ($arr1[$i] !== '{Closure}') {
				$str .= $arr1[$i];
				if (isset($arr2[$i])) {
					$str .= ': ';
				}
			}
			$str .= "{$arr2[$i]}\n";
		}
		return $str;
	}

	/** Convert UNIX timestamp to date and time */
	public static function date(int $unixtime, bool $withTime=true): string {
		return date($withTime ? self::DATETIME : self::DATE, $unixtime);
	}

	/**
	 * Try to interpolate bonus/requirement of an item at an arbitrary QL
	 *
	 * @param int $minQL  The minimum QL
	 * @param int $maxQL  The maximum QL
	 * @param int $minVal The bonus/requirement at `$minQL`
	 * @param int $maxVal The bonus/requirement at `$maxQL`
	 * @param int $ql     The QL for which to calculate the bonus
	 *
	 * @return int The interpolated bonus/requirement at QL $ql
	 */
	public static function interpolate(int $minQL, int $maxQL, int $minVal, int $maxVal, int $ql): int {
		if ($minQL === $maxQL) {
			return $maxVal;
		}
		$result = ($maxVal - $minVal) / ($maxQL - $minQL) * ($ql - $minQL) + $minVal;
		$result = round($result, 0);
		return (int)$result;
	}

	/**
	 * Get an array with all files (not dirs) in a directory
	 *
	 * @return Collection<int,string> An array of file names in that directory
	 */
	public function getFilesInDirectory(string $path): Collection {
		try {
			$files = new Collection($this->fs->listFiles($path));
		} catch (FilesystemException) {
			/** @var array<int,string> $empty */
			$empty = [];
			return new Collection($empty);
		}

		/** @var Collection<int,string> */
		$result = $files->filter(
			fn (string $f): bool => !$this->fs->isDirectory($path . \DIRECTORY_SEPARATOR . $f)
		)->values();
		return $result;
	}

	/**
	 * Get an array with all directories in a directory, excluding . and ..
	 *
	 * @return list<string> An array of dir names in that directory
	 */
	public function getDirectoriesInDirectory(string $path): array {
		try {
			$files = $this->fs->listFiles($path);
		} catch (FilesystemException) {
			return [];
		}

		/** @var list<string> */
		$result = array_values(array_filter(
			$files,
			fn (string $f): bool => $f !== '.' && $f !== '..' && $this->fs->isDirectory($path . \DIRECTORY_SEPARATOR . $f)
		));
		return $result;
	}

	/**
	 * Check if `$class`'s attribute `$attrName` is a ClassSpec annotation.
	 * If so, extract a `Nadybot\Core\ClassSpec` specification from it.
	 *
	 * @return ?ClassSpec A proper class spec, or `null` if not possible
	 *
	 * @phpstan-param class-string $class
	 *
	 * @throws InvalidArgumentException if `$attrName` is not an `NCA\ClassSpec`
	 */
	public static function getClassSpecFromClass(string $class, string $attrName): ?ClassSpec {
		if (!is_subclass_of($attrName, NCA\ClassSpec::class, true)) {
			throw new InvalidArgumentException("{$attrName} is not a class spec");
		}
		$reflection = new ReflectionClass($class);
		$attrs = $reflection->getAttributes($attrName);
		if (!count($attrs)) {
			return null;
		}

		/** @var NCA\ClassSpec */
		$attrObj = $attrs[0]->newInstance();

		$name = $attrObj->name;
		$description = $reflection->getDocComment();
		if ($description === false) {
			throw new \Error("Class {$class} has no description");
		}
		$description = Text::cleanDocComment($description);

		/** @var list<FunctionParameter> */
		$params = [];
		$constructor = $reflection->getConstructor();
		if (isset($constructor)) {
			foreach ($constructor->getParameters() as $param) {
				$params []= self::getParamSpecFromReflection($param);
			}
		}
		return new ClassSpec(
			name: $name,
			class: $class,
			params: $params,
			description: $description,
		);
	}

	/** Create a cryptographically secure password */
	public static function getPassword(int $length=16): string {
		if ($length < 1) {
			throw new InvalidArgumentException('Parameter $length to getPassword() must be > 0');
		}
		$password = base64_encode(random_bytes($length+4));
		return substr(rtrim($password, '='), 0, $length);
	}

	/** Get the value of a given enum */
	public static function enumToValue(BackedEnum $enum): int|string {
		return $enum->value;
	}

	/**
	 * Merge all the keys and values of `$b` into `$a`.
	 * Every value from `$b` overwrite the corresponding value in `$a`, unless
	 * it's an associative array, in which case the keys and values are
	 * merged recursively.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 *
	 * @return array<string,mixed>
	 */
	public static function mergeArraysRecursive(array $a, array $b): array {
		foreach ($b as $key => $value) {
			if (!array_key_exists($key, $a)) {
				$a[$key] = $value;
			} elseif (!is_array($value) || array_is_list($value)) {
				$a[$key] = $value;
			} elseif (!is_array($a[$key]) || array_is_list($a[$key])) {
				$a[$key] = $value;
			} else {
				/** @psalm-suppress MixedArgumentTypeCoercion */
				$a[$key] = self::mergeArraysRecursive($a[$key], $value);
			}
		}
		return $a;
	}

	/**
	 * @template TValue
	 *
	 * @param array<array-key,TValue> $array
	 *
	 * @return TValue
	 *
	 * @throws \ValueError on empty arrays
	 */
	public static function arrayLast(array $array, mixed $default=null): mixed {
		if (count($array) === 0) {
			throw new \ValueError('Call to arrayLast with empty array');
		}
		$last = end($array);
		return $last;
	}

	/**
	 * @template TValue
	 * @template TDefault
	 *
	 * @param array<array-key,TValue> $array
	 * @param TDefault                $default
	 *
	 * @return TValue|TDefault
	 */
	public static function arrayLastOr(array $array, mixed $default): mixed {
		if (count($array) === 0) {
			return $default;
		}
		$last = end($array);
		return $last;
	}

	/**
	 * Exit the running program with an error message
	 *
	 * @param string $message The error message to print
	 * @param int    $sleep   How many seconds to wait before exiting
	 * @param int    $code    The code to exit with
	 *
	 * @return never
	 */
	public static function die(string $message, int $sleep=0, int $code=1): void {
		try {
			\Amp\ByteStream\getStderr()->write($message);
			\Amp\delay($sleep);
		} catch (Error) {
			// @phpstan-ignore-next-line
			fwrite(\STDERR, $message);
			sleep($sleep);
		}
		exit($code);
	}

	/** Get the ParamType for a single parameter to a class spec constructor */
	private static function getParamType(\ReflectionParameter $param, NCA\Param $attr): ParamType {
		$paramRef = "{$param->getDeclaringClass()?->getName()}::{$param->getDeclaringFunction()->getName()}(\${$param->getName()})";
		if (isset($attr->type)) {
			return $attr->type;
		}
		$paramType = $param->getType();
		if (!isset($paramType)) {
			throw new Exception("Parameter {$paramRef} has no type");
		} elseif (!($paramType instanceof ReflectionNamedType)) {
			throw new Exception("Parameter {$paramRef} must have exactly one single type");
		}
		$type = $paramType->getName();
		return match ($type) {
			'bool' => ParamType::Bool,
			'string' => ParamType::String,
			'int' => ParamType::Int,
			default => throw new Exception("Parameter type {$type} in {$paramRef} needs explicit type"),
		};
	}

	/** Extract a `FunctionParameter` instance from a given method parameter */
	private static function getParamSpecFromReflection(\ReflectionParameter $param): FunctionParameter {
		$paramRef = "{$param->getDeclaringClass()?->getName()}::{$param->getDeclaringFunction()->getName()}(\${$param->getName()})";
		$attrs = $param->getAttributes(NCA\Param::class, ReflectionAttribute::IS_INSTANCEOF);
		if (!count($attrs)) {
			throw new Exception("{$paramRef} has no Param attribute");
		}
		$paramObj = $attrs[0]->newInstance();
		$paramType = self::getParamType($param, $paramObj);
		$description = $param->getDeclaringFunction()->getDocComment();
		if ($description === false) {
			throw new \Error(
				"{$param->getDeclaringClass()?->name}::{$param->getDeclaringFunction()->name}() ".
				'has no description'
			);
		}
		$description = trim(Safe::pregReplace("|^/\*\*(.*)\*/|s", '$1', $description));
		$description = Safe::pregReplace("/^[ \t]*\*[ \t]*/m", '', $description);
		$matches = Safe::pregMatch('/@param (?:.*?) \$'.$param->getName().'\s+([^@]+)/s', $description);
		if (!count($matches)) {
			throw new Exception("{$paramRef} has no @param description");
		}
		$description = Text::cleanDocComment($matches[1]);
		$result = new FunctionParameter(
			name: $paramObj->name ?? $param->getName(),
			description: trim($description),
			required: $param->isDefaultValueAvailable() === false,
			type: $paramType,
		);
		return $result;
	}
}
