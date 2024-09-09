<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{date, getcwd};
use Amp\File\FilesystemException;
use BackedEnum;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Iterator;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Types\MinMax;
use RangeException;

use ReflectionClass;
use UnhandledMatchError;

#[NCA\Instance]
class Util {
	/** @var string */
	public const DATETIME = 'd-M-Y H:i T';

	/** @var string */
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
	 * @return int The duration in seconds
	 */
	public static function parseTime(string $budatime): int {
		$unixtime = 0;

		$matches = [];
		$pattern = '/([0-9]+)([a-z]+)/';

		/** @var list<list{string,numeric-string,string}> */
		$matches = Safe::pregMatchOrderedAll($pattern, $budatime);

		try {
			foreach ($matches as $match) {
				$quantifier = match ($match[2]) {
					'y','yr','year','years' => 31_536_000,
					'mo','month','months' => 2_592_000,
					'weeks','week','w' => 604_800,
					'days','day','d' => 86_400,
					'hours','hour','hrs','hr','h' => 3_600,
					'mins','min','m' => 60,
					'secs','sec','s' => 1,
				};
				$unixtime += (int)$match[1] * $quantifier;
			}
		} catch (UnhandledMatchError) {
			return 0;
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
	 * Try to expand or shorten an ability
	 *
	 * e.g. AGI -> Agility, SEN -> Sense
	 * or Sense -> SEN if $getFullName set to false
	 *
	 * @param string $ability     The short or long form
	 * @param bool   $getFullName true if you want to expand, false if you want to shorten
	 *
	 * @return string|null The short or long form
	 */
	public static function getAbility(string $ability, bool $getFullName=false): ?string {
		$abilities = [
			'agi' => 'Agility',
			'int' => 'Intelligence',
			'psy' => 'Psychic',
			'sta' => 'Stamina',
			'str' => 'Strength',
			'sen' => 'Sense',
		];

		$ability = strtolower(substr($ability, 0, 3));

		if (!isset($abilities[$ability])) {
			return null;
		}
		if ($getFullName) {
			return $abilities[$ability];
		}
		return $ability;
	}

	/**
	 * Randomly get a value from an array
	 *
	 * @param array<mixed> $array
	 */
	public static function randomArrayValue(array $array): mixed {
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
	 * Create a random string of $length characters
	 *
	 * @see http://www.lost-in-code.com/programming/php-code/php-random-string-with-numbers-and-letters/
	 */
	public static function genRandomString(int $length=10, string $characters='0123456789abcdefghijklmnopqrstuvwxyz'): string {
		$string = '';
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}
		return $string;
	}

	/** Get a stacktrace of the calling stack as a string */
	public static function getStackTrace(): string {
		$trace = debug_backtrace();
		$arr1 = [];
		$arr2 = [];
		foreach ($trace as $obj) {
			$file = str_replace(getcwd() . '/', '', ($obj['file'] ?? '{Closure}'));
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
			$files = collect($this->fs->listFiles($path));
		} catch (FilesystemException) {
			/** @var array<int,string> $empty */
			$empty = [];
			return collect($empty);
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

	/** Calculate the title level from the player's level */
	public static function levelToTL(int $level): int {
		if ($level < 15) {
			return 1;
		}
		if ($level < 50) {
			return 2;
		}
		if ($level < 100) {
			return 3;
		}
		if ($level < 150) {
			return 4;
		}
		if ($level < 190) {
			return 5;
		}
		if ($level < 205) {
			return 6;
		}
		return 7;
	}

	/** Calculate the level range from the player's title level */
	public static function tlToLevelRange(int $tl): MinMax {
		if ($tl === 1) {
			return new MinMax(min: 1, max: 14);
		}
		if ($tl === 2) {
			return new MinMax(min: 15, max: 49);
		}
		if ($tl === 3) {
			return new MinMax(min: 50, max: 99);
		}
		if ($tl === 4) {
			return new MinMax(min: 100, max: 149);
		}
		if ($tl === 5) {
			return new MinMax(min: 150, max: 189);
		}
		if ($tl === 6) {
			return new MinMax(min: 190, max: 204);
		}
		return new MinMax(min: 205, max: 220);
	}

	/** @phpstan-param class-string $class */
	public static function getClassSpecFromClass(string $class, string $attrName): ?ClassSpec {
		if (!is_subclass_of($attrName, NCA\ClassSpec::class)) {
			throw new InvalidArgumentException("{$attrName} is not a class spec");
		}
		$reflection = new ReflectionClass($class);
		$attrs = $reflection->getAttributes($attrName);
		if (!count($attrs)) {
			return null;
		}

		/** @var NCA\ClassSpec */
		$attrObj = $attrs[0]->newInstance();

		/** @phpstan-var class-string */
		$name = $attrObj->name;

		/** @var list<FunctionParameter> */
		$params = [];
		$i = 1;
		foreach ($reflection->getAttributes(NCA\Param::class) as $paramAttr) {
			$paramObj = $paramAttr->newInstance();
			$paramType = match ($paramObj->type) {
				FunctionParameter::TYPE_BOOL,
				FunctionParameter::TYPE_SECRET,
				FunctionParameter::TYPE_STRING,
				FunctionParameter::TYPE_INT,
				FunctionParameter::TYPE_STRING_ARRAY => $paramObj->type,
				'integer' => FunctionParameter::TYPE_INT,
				'boolean' => FunctionParameter::TYPE_BOOL,
				default => throw new Exception("Unknown parameter type {$paramObj->type} in {$class}"),
			};
			$params []= new FunctionParameter(
				name: $paramObj->name,
				description: $paramObj->description??null,
				required: $paramObj->required,
				type: $paramType,
			);
			$i++;
		}
		return new ClassSpec(
			name: $name,
			class: $class,
			params: $params,
			description: $attrObj->description,
		);
	}

	/** Create a valid UUID that is unique worldwide */
	public static function createUUID(): string {
		$data = random_bytes(16);

		// Set version to 0100
		$data[6] = chr(ord($data[6]) & 0x0F | 0x40);
		// Set bits 6-7 to 10
		$data[8] = chr(ord($data[8]) & 0x3F | 0x80);

		// Output the 36 character UUID.
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/** Create a cryptographically secure password */
	public static function getPassword(int $length=16): string {
		if ($length < 1) {
			throw new InvalidArgumentException('Parameter $length to getPassword() must be > 0');
		}
		$password = base64_encode(random_bytes($length+4));
		return substr(rtrim($password, '='), 0, $length);
	}

	public static function enumToValue(BackedEnum $enum): int|string {
		return $enum->value;
	}

	/**
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
				$a[$key] = self::mergeArraysRecursive($a[$key], $value);
			}
		}
		return $a;
	}

	/**
	 * Convert the given iterable into an iterator
	 *
	 * @template T
	 *
	 * @param iterable<T> $iter
	 *
	 * @return Iterator<T>
	 */
	public static function toIterator(iterable $iter): Iterator {
		if (is_array($iter)) {
			return new \ArrayIterator($iter);
		}

		/** @disregard P1006 */
		return new \IteratorIterator($iter);
	}
}
