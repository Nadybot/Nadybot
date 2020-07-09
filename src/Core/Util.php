<?php

namespace Budabot\Core;

/**
 * @Instance
 */
class Util {

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/** @var string */
	const DATETIME = "d-M-Y H:i T";

	/**
	 * Convert bytes to kB, MB, etc. so it's never more than 1024
	 *
	 * @param int $bytes
	 * @return string Converted unit, e.g. "1.2 GB"
	 */
	public function bytesConvert($bytes) {
		$ext = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
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
	 *
	 * @param int $time The duration in seconds
	 * @param boolean $showSeconds If set to false, cut off seconds
	 * @return string A human readable string
	 */
	public function unixtimeToReadable($time, $showSeconds=true) {
		if ($time == 0) {
			return '0 secs';
		}

		$units = [
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
	 * @param string $budatime A humanm readable duration
	 * @return int The duration in seconds
	 */
	public function parseTime($budatime) {
		$unixtime = 0;

		$matches = array();
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
	 * @param string $ver1 First Version number
	 * @param string $ver2 Second Version number
	 * @return int 1 if the first is greater than the second,
	 *             -1 if the second is greater than the first and
	 *             0 if they are equal.
	 */
	public function compareVersionNumbers($ver1, $ver2) {
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
		} else {
			return 0;
		}
	}

	/**
	 * Returns the full profession name given the search string passed in
	 *
	 * @param string $search A short-form like "crat", "adv" or "enfo"
	 * @return string The fully qualified name of the found profession or an empty string
	 */
	public function getProfessionName($search) {
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
	 *
	 * @param string $profession Full name of the profession, e.g. "Adventurer"
	 * @return string Short name of the profession, e.g. "Adv"
	 */
	public function getProfessionAbbreviation($profession) {
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
	 *
	 * @param string $filename
	 * @return string Either the full filename or an empty string
	 */
	public function verifyFilename($filename) {
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
	 * @return string|null The short or lonf form
	 */
	public function getAbility($ability, $getFullName=false) {
		$abilities = array(
			'agi' => 'Agility',
			'int' => 'Intelligence',
			'psy' => 'Psychic',
			'sta' => 'Stamina',
			'str' => 'Strength',
			'sen' => 'Sense'
		);

		$ability = strtolower(substr($ability, 0, 3));

		if (isset($abilities[$ability])) {
			if ($getFullName) {
				return $abilities[$ability];
			} else {
				return $ability;
			}
		} else {
			return null;
		}
	}

	/**
	 * Randomly get an element from an array
	 *
	 * @param mixed[] $array The array to get an element from
	 * @return mixed A random element
	 */
	public function randomArrayValue($array) {
		return $array[rand(0, count($array) - 1)];
	}

	/**
	 * Checks to see if the $sender is valid
	 *
	 * Invalid values: -1 on 32bit and 4294967295  on 64bit
	 *
	 * @param int $sender
	 * @return boolean
	 */
	public function isValidSender($sender) {
		return (int)0xFFFFFFFF == $sender ? false : true;
	}

	/**
	 * Create a random string of $length characters
	 *
	 * @see http://www.lost-in-code.com/programming/php-code/php-random-string-with-numbers-and-letters/
	 * @param int $length How long the string should be
	 * @param strings $characters A string containing only letters to pick from
	 * @return string A random string with length $length
	 */
	public function genRandomString($length=10, $characters='0123456789abcdefghijklmnopqrstuvwxyz') {
		$string = '';
		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters))];
		}
		return $string;
	}

	/**
	 * Get a stacktrace of the calling stack as a string
	 *
	 * @return string The stacktrace that lead to this call
	 */
	public function getStackTrace() {
		$trace = debug_backtrace();
		$arr1 = array();
		$arr2 = array();
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
	 *
	 * @param int $unixtime The UNIX timestamp
	 * @return string A string of the given timestamp as date and time with timezone
	 */
	public function date($unixtime) {
		return date(self::DATETIME, $unixtime);
	}

	/**
	 * Checks if $string ends with string $test
	 *
	 * @see http://stackoverflow.com/questions/834303/php-startswith-and-endswith-functions
	 * @param string $string Haystack
	 * @param string $test Needle
	 * @return bool
	 */
	public function endsWith($string, $test) {
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
	 * @param string $haystack
	 * @param string $needle
	 * @return bool
	 */
	public function startsWith($haystack, $needle) {
		return !strncmp($haystack, $needle, strlen($needle));
	}

	/**
	 * Remove all colors from $msg
	 *
	 * @param string $msg String to remove collor tags from
	 * @return string The cleared string
	 */
	public function stripColors($msg) {
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
	public function generateQueryFromParams($params, $column) {
		$queryParams = array();
		$statements = array();
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
		return array(join(" AND ", $statements), $queryParams);
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
	public function mergesort(&$array, $cmp_function) {
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
		$array = array();
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
	 *
	 * @param int $minQL The QL at which requirement/bonus is $minVal
	 * @param int $maxQL The QL at which requirement/bonus is $maxVal
	 * @param int $minVal The bonus/requirement at QL $minQL
	 * @param int $maxVal The bonus/requirement at QL $maxQL
	 * @param int $ql The QL for which to interpolate the bonus/requirement
	 * @return int The interpolated bonus/requirement at QL $ql
	 */
	public function interpolate($minQL, $maxQL, $minVal, $maxVal, $ql) {
		if ($minQL == $maxQL) {
			return $maxVal;
		}
		$result = ($maxVal - $minVal) / ($maxQL - $minQL) * ($ql - $minQL) + $minVal;
		$result = round($result, 0);
		return $result;
	}

	/**
	 * Run a function over an associative array and glue the results together
	 *
	 * @param array $arr The associative array we want to feed bit by bit into $func
	 * @param string $glue The string to join the outputs of $func on
	 * @param callable $func The function to run for each key and value, returns a string or null
	 * @return string The glued together result
	 */
	public function mapFilterCombine($arr, $glue, $func) {
		$newArr = array();
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
	 * @param string $path The directory to list
	 * @return string[] An array of file names in that directory
	 */
	public function getFilesInDirectory($path) {
		return array_values(array_filter(scandir($path), function ($f) use ($path) {
			return !is_dir($path . DIRECTORY_SEPARATOR . $f);
		}));
	}

	/**
	 * Get an array with all directories in a directory, excluding . and ..
	 *
	 * @param string $path The directory to list
	 * @return string[] An array of dir names in that directory
	 */
	public function getDirectoriesInDirectory($path) {
		return array_values(array_filter(scandir($path), function ($f) use ($path) {
			return $f != '.' && $f != '..' && is_dir($path . DIRECTORY_SEPARATOR . $f);
		}));
	}

	/**
	 * Test if $input only consists of digits
	 *
	 * @param mixed $input The variable to test
	 * @return boolean true if $input would qualify as a valid integer
	 */
	public function isInteger($input) {
		return(ctype_digit(strval($input)));
	}
}
