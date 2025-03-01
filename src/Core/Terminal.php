<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{fclose, sapi_windows_cp_set, sapi_windows_vt100_support, shell_exec, stream_get_contents};

use Safe\Exceptions\MiscException;
use Throwable;

/*
 * This file is based on a Symfony package
 * by Fabien Potencier <fabien@symfony.com>
 * It provides functions to determine some terminal capabilities
 * and properties.
 */
class Terminal {
	/** The cached terminal width in characters */
	private static ?int $width = null;

	/** The cached terminal height in characters */
	private static ?int $height = null;

	/** cached value, whether this terminal supports stty */
	private static ?bool $stty = null;

	/** Gets the terminal width in characters */
	public static function getWidth(): int {
		$width = getenv('COLUMNS');
		if (is_string($width)) {
			return (int)trim($width);
		}

		if (!isset(self::$width)) {
			try {
				self::initDimensions();
			} catch (Throwable) {
			}
		}

		return self::$width ??= 80;
	}

	/** Gets the terminal height in characters */
	public static function getHeight(): int {
		$height = getenv('LINES');
		if (is_string($height)) {
			return (int)trim($height);
		}

		if (!isset(self::$height)) {
			try {
				self::initDimensions();
			} catch (Throwable) {
			}
		}

		return self::$height ??= 24;
	}

	/** Utility function to check whether the bot is running Windows */
	private static function isWindows(): bool {
		return strtoupper(substr(\PHP_OS_FAMILY, 0, 3)) === 'WIN';
	}

	/** Check whether the terminal has the `stty` command available */
	private static function hasSttyAvailable(): bool {
		if (isset(self::$stty)) {
			return self::$stty;
		}

		// skip check if shell_exec function is disabled
		if (!\function_exists('shell_exec')) {
			return false;
		}

		$devNull = self::isWindows() ? 'NUL' : '/dev/null';
		return self::$stty = (bool)shell_exec("stty 2> {$devNull}");
	}

	/** Initialize the dimensions of the terminal and cache them */
	private static function initDimensions(): void {
		if (self::isWindows() === false) {
			self::initDimensionsUsingStty();
			return;
		}
		$ansicon = getenv('ANSICON');
		if (is_string($ansicon) && count($matches = Safe::pregMatch('/^(\d+)x(\d+)(?: \((\d+)x(\d+)\))?$/', trim($ansicon)))) {
			// extract [w, H] from "wxh (WxH)"
			// or [w, h] from "wxh"
			self::$width = (int)$matches[1];
			self::$height = isset($matches[4]) ? (int)$matches[4] : (int)$matches[2];
			return;
		}
		try {
			sapi_windows_vt100_support(\STDOUT);
			$hasVt100 = true;
		} catch (MiscException) {
			$hasVt100 = false;
		}
		if (!$hasVt100 && self::hasSttyAvailable()) {
			// only use stty on Windows if the terminal does not support vt100 (e.g. Windows 7 + git-bash)
			// testing for stty in a Windows 10 vt100-enabled console will implicitly disable vt100 support on STDOUT
			self::initDimensionsUsingStty();
			return;
		}
		if (null !== ($dimensions = self::getConsoleMode())) {
			self::$width = $dimensions[0];
			self::$height = $dimensions[1];
		}
	}

	/** Initializes dimensions using the output of an stty columns line. */
	private static function initDimensionsUsingStty(): void {
		if (null === ($sttyString = self::getSttyColumns())) {
			return;
		}
		if (count($matches = Safe::pregMatch('/rows.(\d+);.columns.(\d+);/is', $sttyString))) {
			// extract [w, h] from "rows h; columns w;"
			self::$width = (int)$matches[2];
			self::$height = (int)$matches[1];
		} elseif (count($matches = Safe::pregMatch('/;.(\d+).rows;.(\d+).columns/is', $sttyString))) {
			// extract [w, h] from "; h rows; w columns"
			self::$width = (int)$matches[2];
			self::$height = (int)$matches[1];
		}
	}

	/**
	 * Runs and parses mode CON if it's available, suppressing any error output.
	 *
	 * @return null|int[] An array composed of the width and the height or null if it could not be parsed
	 *
	 * @psalm-return null|array{0:int,1:int}
	 */
	private static function getConsoleMode(): ?array {
		$info = self::readFromProcess('mode CON');

		if (!isset($info)) {
			return null;
		}
		if (!count($matches = Safe::pregMatch('/--------+\r?\n.+?(\d+)\r?\n.+?(\d+)\r?\n/', $info))) {
			return null;
		}

		return [(int)$matches[2], (int)$matches[1]];
	}

	/** Runs and parses `stty -a` if it's available, suppressing any error output. */
	private static function getSttyColumns(): ?string {
		return self::readFromProcess(['stty', '-a']);
	}

	/**
	 * Read the output from a given command
	 *
	 * @param string|list<string> $command
	 */
	private static function readFromProcess(string|array $command): ?string {
		if (!\function_exists('proc_open')) {
			return null;
		}

		$descriptorspec = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$codepage = \function_exists('sapi_windows_cp_set') ? sapi_windows_cp_get() : 0;

		if (!$process = @proc_open($command, $descriptorspec, $pipes, null, null, ['suppress_errors' => true])) {
			return null;
		}

		$info = stream_get_contents($pipes[1]);
		// @phpstan-ignore-next-line
		fclose($pipes[1]);
		// @phpstan-ignore-next-line
		fclose($pipes[2]);
		proc_close($process);

		if ($codepage) {
			sapi_windows_cp_set($codepage);
		}

		return $info;
	}
}
