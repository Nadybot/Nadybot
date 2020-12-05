<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class Util {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var string */
	public const DATETIME = "d-M-Y H:i T";

	/**
	 * Convert bytes to kB, MB, etc. so it's never more than 1024
	 */
	public function bytesConvert(int $bytes): string {
		$ext = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
		$unitCount = 0;
		for ($max = count($ext) - 1; $bytes >= 1024 && $unitCount < $max; $unitCount++) {
			$bytes /= 1024;
		}
		return round($bytes, 2) ." ". $ext[$unitCount];
	}

	/**
	 * Converts a duration in seconds into a human readable format
	 *
	 * Converts 3688 to "1hr, 1min, 18secs"
	 */
	public function unixtimeToReadable(int $time, bool $showSeconds=true): string {
		if ($time == 0) {
			return '0 secs';
		}

		$units = [
			"year" => 31536000,
			"day" => 86400,
			"hr" => 3600,
			"min" => 60,
			"sec" => 1
		];

		$timeshift = '';
		foreach ($units as $unit => $seconds) {
			if ($time > 0) {
				$length = floor($time / $seconds);
			} else {
				$length = ceil($time / $seconds);
			}
			if ($unit != "sec" || $showSeconds || $timeshift == '') {
				if ($length > 1) {
					$timeshift .= $length . " " . $unit . "s ";
				} elseif ($length == 1) {
					$timeshift .= $length . " " . $unit . " ";
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
	 * @return int The duration in seconds
	 */
	public function parseTime(string $budatime): int {
		$unixtime = 0;

		$matches = [];
		$pattern = '/([0-9]+)([a-z]+)/';
		preg_match_all($pattern, $budatime, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			switch ($match[2]) {
				case 'y':
				case 'yr':
				case 'year':
				case 'years':
					$unixtime += $match[1] * 31536000;
					break;
				case 'mo':
				case 'month':
				case 'months':
					$unixtime += $match[1] * 2592000;
					break;
				case 'weeks':
				case 'week':
				case 'w':
					$unixtime += $match[1] * 604800;
					break;
				case 'days':
				case 'day':
				case 'd':
					$unixtime += $match[1] * 86400;
					break;
				case 'hours':
				case 'hour':
				case 'hrs':
				case 'hr':
				case 'h':
					$unixtime += $match[1] * 3600;
					break;
				case 'mins':
				case 'min':
				case 'm':
					$unixtime += $match[1] * 60;
					break;
				case 'secs':
				case 'sec':
				case 's':
					$unixtime += $match[1];
					break;
				default:
					return 0;
			}
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
	public function compareVersionNumbers(string $ver1, string $ver2): int {
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

	/**
	 * Returns the full profession name given the search string passed in
	 */
	public function getProfessionName(string $search): string {
		$search = strtolower($search);
		switch ($search) {
			case "adv":
			case "advy":
			case "adventurer":
				$prof = "Adventurer";
				break;
			case "agent":
				$prof = "Agent";
				break;
			case "crat":
			case "bureaucrat":
				$prof = "Bureaucrat";
				break;
			case "doc":
			case "doctor":
				$prof = "Doctor";
				break;
			case "enf":
			case "enfo":
			case "enforcer":
				$prof = "Enforcer";
				break;
			case "eng":
			case "engi":
			case "engy":
			case "engineer":
				$prof = "Engineer";
				break;
			case "fix":
			case "fixer":
				$prof = "Fixer";
				break;
			case "keep":
			case "keeper":
				$prof = "Keeper";
				break;
			case "ma":
			case "martial":
			case "martialartist":
			case "martial artist":
				$prof = "Martial Artist";
				break;
			case "mp":
			case "meta":
			case "metaphysicist":
			case "meta-physicist":
				$prof = "Meta-Physicist";
				break;
			case "nt":
			case "nano":
			case "nanotechnician":
			case "nano-technician":
				$prof = "Nano-Technician";
				break;
			case "sol":
			case "sold":
			case "soldier":
				$prof = "Soldier";
				break;
			case "tra":
			case "trad":
			case "trader":
				$prof = "Trader";
				break;
			case "shade":
				$prof = "Shade";
				break;
			default:
				$prof = '';
		}

		return $prof;
	}

	/**
	 * Get the short form for a fully qualified profession name
	 *
	 * Adventurer becomes Adv, etc.
	 */
	public function getProfessionAbbreviation(string $profession): string {
		switch ($profession) {
			case "Adventurer":
				$prof = "Adv";
				break;
			case "Agent":
				$prof = "Agent";
				break;
			case "Bureaucrat":
				$prof = "Crat";
				break;
			case "Doctor":
				$prof = "Doc";
				break;
			case "Enforcer":
				$prof = "Enf";
				break;
			case "Engineer":
				$prof = "Eng";
				break;
			case "Fixer":
				$prof = "Fixer";
				break;
			case "Keeper":
				$prof = "Keeper";
				break;
			case "Martial Artist":
				$prof = "MA";
				break;
			case "Meta-Physicist":
				$prof = "MP";
				break;
			case "Nano-Technician":
				$prof = "NT";
				break;
			case "Soldier":
				$prof = "Sol";
				break;
			case "Trader":
				$prof = "Trader";
				break;
			case "Shade":
				$prof = "Shade";
				break;
			default:
				$prof = "Unknown";
				break;
		}

		return $prof;
	}

	/**
	 * Completes a filename or directory by searching for it in modules and core paths
	 */
	public function verifyFilename(string $filename): string {
		//Replace all \ characters with /
		$filename = str_replace("\\", "/", $filename);

		//check if the file exists
		foreach (array_reverse($this->chatBot->vars['module_load_paths']) as $modulePath) {
			if (file_exists("$modulePath/$filename")) {
				return "$modulePath/$filename";
			}
		}
		if (file_exists(__DIR__ . "/$filename")) {
			return __DIR__ . "/$filename";
		}
		if (file_exists(__DIR__ . "/Modules/$filename")) {
			return __DIR__ . "/Modules/$filename";
		}
		if (file_exists($filename)) {
			return $filename;
		}
		return "";
	}

	/**
	 * Try to expand or shorten an ability
	 *
	 * e.g. AGI -> Agility, SEN -> Sense
	 * or Sense -> SEN if $getFullName set to false
	 *
	 * @param string $ability The short or long form
	 * @param boolean $getFullName true if you want to expand, false if you want to shorten
	 * @return string|null The short or long form
	 */
	public function getAbility(string $ability, bool $getFullName=false): ?string {
		$abilities = [
			'agi' => 'Agility',
			'int' => 'Intelligence',
			'psy' => 'Psychic',
			'sta' => 'Stamina',
			'str' => 'Strength',
			'sen' => 'Sense'
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
	 * @return mixed A random element
	 */
	public function randomArrayValue(array $array) {
		return $array[array_rand($array)];
	}

	/**
	 * Checks to see if the $sender is valid
	 *
	 * Invalid values: -1 on 32bit and 4294967295  on 64bit
	 *
	 * @param int|string $sender
	 */
	public function isValidSender($sender): bool {
		$isValid = !in_array(
			$sender,
			[(string)0xFFFFFFFF, (int)0xFFFFFFFF, 0xFFFFFFFF, "-1", -1],
			true
		);
		return $isValid;
	}

	/**
	 * Create a random string of $length characters
	 *
	 * @see http://www.lost-in-code.com/programming/php-code/php-random-string-with-numbers-and-letters/
	 */
	public function genRandomString(int $length=10, string $characters='0123456789abcdefghijklmnopqrstuvwxyz'): string {
		$string = '';
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters))];
		}
		return $string;
	}

	/**
	 * Get a stacktrace of the calling stack as a string
	 */
	public function getStackTrace(): string {
		$trace = debug_backtrace();
		$arr1 = [];
		$arr2 = [];
		foreach ($trace as $obj) {
			$file = str_replace(getcwd(), "", $obj['file']);
			$arr1 []= "{$file}({$obj['line']})";
			$arr2 []= "{$obj['function']}()";
		}

		array_shift($arr2);

		$str = '';
		for ($i = 0; $i < count($arr1); $i++) {
			$str .= "$arr1[$i] : $arr2[$i]\n";
		}
		return $str;
	}

	/**
	 * Convert UNIX timestamp to date and time
	 */
	public function date(int $unixtime): string {
		return date(self::DATETIME, $unixtime);
	}

	/**
	 * Checks if $string ends with string $test
	 *
	 * @see http://stackoverflow.com/questions/834303/php-startswith-and-endswith-functions
	 */
	public function endsWith(string $string, string $test): bool {
		$strlen = strlen($string);
		$testlen = strlen($test);
		if ($testlen > $strlen) {
			return false;
		}
		return substr_compare($string, $test, -$testlen) === 0;
	}

	/**
	 * Checks if $haystack starts with $needle
	 *
	 * @see http://stackoverflow.com/questions/834303/php-startswith-and-endswith-functions
	 */
	public function startsWith(string $haystack, string $needle): bool {
		return !strncmp($haystack, $needle, strlen($needle));
	}

	/**
	 * Remove all colors from $msg
	 */
	public function stripColors(string $msg): string {
		$msg = preg_replace("~<font color=#.{6}>~", "", $msg);
		$msg = preg_replace("~</font>~", "", $msg);
		return $msg;
	}

	/**
	 * Generate an SQL query from a column and a list of criterias
	 *
	 * @param string[] $params An array of strings that $column must contain (or not contain if they start with "-")
	 * @param string $column The table column to test agains
	 * @return array<string,string[]> ["$column LIKE ? AND $column NOT LIKE ? AND $column LIKE ?", ['%a%', '%b%', '%c%']]
	 */
	public function generateQueryFromParams(array $params, string $column): array {
		$queryParams = [];
		$statements = [];
		foreach ($params as $key => $value) {
			if ($value[0] == "-" && strlen($value) > 1) {
				$value = substr($value, 1);
				$op = "NOT LIKE";
			} else {
				$op = "LIKE";
			}
			$statements []= "$column $op ?";
			$queryParams []= '%' . $value . '%';
		}
		return [join(" AND ", $statements), $queryParams];
	}

	/**
	 * A stable sort, which keeps the order of equal elements in the input and output
	 *
	 * @see http://php.net/manual/en/function.usort.php
	 * @param mixed[] $array A reference to the array to sorte
	 * @param callable $cmp_function The function (name) to compare elements with.
	 *                               Must accept 2 parameters and return
	 *                               1 (1st before), -1 (2nd before) ot 0 (equal)
	 * @return void
	 */
	public function mergesort(array &$array, callable $cmp_function): void {
		// Arrays of size < 2 require no action.
		if (count($array) < 2) {
			return;
		}
		// Split the array in half
		$halfway = count($array) / 2;
		$array1 = array_slice($array, 0, $halfway);
		$array2 = array_slice($array, $halfway);
		// Recurse to sort the two halves
		$this->mergesort($array1, $cmp_function);
		$this->mergesort($array2, $cmp_function);
		// If all of $array1 is <= all of $array2, just append them.
		if ($cmp_function(end($array1), $array2[0]) < 1) {
			$array = array_merge($array1, $array2);
			return;
		}
		// Merge the two sorted arrays into a single sorted array
		$array = [];
		$ptr1 = $ptr2 = 0;
		while ($ptr1 < count($array1) && $ptr2 < count($array2)) {
			if ($cmp_function($array1[$ptr1], $array2[$ptr2]) < 1) {
				$array[] = $array1[$ptr1++];
			} else {
				$array[] = $array2[$ptr2++];
			}
		}
		// Merge the remainder
		while ($ptr1 < count($array1)) {
			$array[] = $array1[$ptr1++];
		}
		while ($ptr2 < count($array2)) {
			$array[] = $array2[$ptr2++];
		}
		return;
	}

	/**
	 * Try to interpolate bonus/requirement of an item at an arbitrary QL
	 * @return int The interpolated bonus/requirement at QL $ql
	 */
	public function interpolate(int $minQL, int $maxQL, int $minVal, int $maxVal, int $ql): int {
		if ($minQL == $maxQL) {
			return $maxVal;
		}
		$result = ($maxVal - $minVal) / ($maxQL - $minQL) * ($ql - $minQL) + $minVal;
		$result = round($result, 0);
		return (int)$result;
	}

	/**
	 * Run a function over an associative array and glue the results together with $glue
	 */
	public function mapFilterCombine(array $arr, string $glue, callable $func): string {
		$newArr = [];
		foreach ($arr as $key => $value) {
			$result = call_user_func($func, $key, $value);
			if ($result !== null) {
				$newArr []= $result;
			}
		}
		return implode($glue, $newArr);
	}

	/**
	 * Get an array with all files (not dirs) in a directory
	 *
	 * @return string[] An array of file names in that directory
	 */
	public function getFilesInDirectory(string $path): array {
		return array_values(array_filter(scandir($path), function ($f) use ($path) {
			return !is_dir($path . DIRECTORY_SEPARATOR . $f);
		}));
	}

	/**
	 * Get an array with all directories in a directory, excluding . and ..
	 *
	 * @return string[] An array of dir names in that directory
	 */
	public function getDirectoriesInDirectory(string $path): array {
		return array_values(array_filter(scandir($path), function ($f) use ($path) {
			return $f !== '.' && $f !== '..' && is_dir($path . DIRECTORY_SEPARATOR . $f);
		}));
	}

	/**
	 * Test if $input only consists of digits
	 */
	public function isInteger($input): bool {
		return(ctype_digit(strval($input)));
	}

	/** Calculate the title level from the player's level */
	public function levelToTL(int $level): int {
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
}
