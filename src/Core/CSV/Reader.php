<?php declare(strict_types=1);

namespace Nadybot\Core\CSV;

use Exception;

class Reader {
	private string $file;

	public function __construct(string $file) {
		$this->file = $file;
	}

	/**
	 * Get a line from the CSV as hash
	 */
	public function items(): iterable {
		$file = fopen($this->file, 'r');
		if ($file === false) {
			return null;
		}
		if (!feof($file)) {
			$headers = fgetcsv($file, 8192);
			if (count($headers) === 1 && $headers[0][0] === "#") {
				$headers = fgetcsv($file, 8192);
			}
		}
		$numCols = count($headers);
		while (!feof($file)) {
			$line = fgets($file);
			// $row = fgetcsv($file, 8192);
			if ($line === false) {
				if (feof($file)) {
					return null;
				}
			}
			$line = preg_replace("/^,/", "\x00,", $line);
			$line = preg_replace("/,$/", ",\x00", rtrim($line));
			$line = preg_replace("/,(?=,)/", ",\x00", $line);
			$row = str_getcsv($line);
			if (!is_array($row)) {
				throw new Exception("Error reading row from {$this->file}");
			}
			if ($row === [null]) { // Skip blank lines
				continue;
			}
			for ($i = 0; $i < $numCols; $i++) {
				if ($row[$i] === "\x00") {
					$row[$i] = null;
				}
			}

			yield array_combine($headers, $row);
		}

		return null;
	}
}
