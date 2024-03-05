<?php declare(strict_types=1);

namespace Nadybot\Core\CSV;

use function Safe\{fgetcsv, fopen};

class Reader {
	private string $file;

	public function __construct(string $file) {
		$this->file = $file;
	}

	/**
	 * Get a line from the CSV as hash
	 *
	 * @return array<mixed>|\Generator<array<string,mixed>>
	 */
	public function items(): iterable {
		$file = fopen($this->file, 'r');
		$numCols = 0;
		if (!feof($file)) {
			$headers = fgetcsv($file, 8192);
			while (is_array($headers) && count($headers) === 1 && isset($headers[0]) && is_string($headers[0]) && $headers[0][0] === "#") {
				$headers = fgetcsv($file, 8192);
			}
			if (!is_array($headers)) {
				return [];
			}
			$numCols = count($headers);
		}
		while (!feof($file)) {
			$line = fgets($file);
			// $row = fgetcsv($file, 8192);
			if ($line === false) {
				if (feof($file)) {
					return [];
				}
				continue;
			}
			$line = preg_replace("/^,/", "\x00,", $line);
			$line = preg_replace("/,$/", ",\x00", rtrim($line));
			$line = preg_replace("/,(?=,)/", ",\x00", $line);
			$row = str_getcsv($line);
			if ($row === [null]) { // Skip blank lines
				continue;
			}
			for ($i = 0; $i < $numCols; $i++) {
				if ($row[$i] === "\x00") {
					$row[$i] = null;
				}
			}

			yield array_combine($headers??[], $row);
		}

		return [];
	}
}
