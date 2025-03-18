<?php declare(strict_types=1);

namespace Nadybot\Core\CSV;

use function Amp\ByteStream\splitLines;
use function Safe\array_combine;

use Generator;
use IteratorIterator;
use Nadybot\Core\{Filesystem, Safe};

/**
 * This is a low memory class that allows reading CSV files line by line
 * by converting each line into an associative array and treating
 * a `,,` as a null value, forcing `,"",` to get an empty string
 */
class Reader {
	/** @param string $file The full filename of the CSV file */
	public function __construct(
		private string $file,
		private Filesystem $filesystem,
	) {
	}

	/**
	 * Get a line from the CSV as an associative array
	 *
	 * @return Generator<int,array<string,?string>>
	 *
	 * @phpstan-ignore-next-line
	 */
	public function items(): Generator {
		$file = $this->filesystem->openFile($this->file, 'r');
		if ($file->eof()) {
			$file->close();
			return [];
		}
		$iter = new IteratorIterator(splitLines($file));
		$iter->rewind();
		if ($iter->valid() === false) {
			$file->close();
			return [];
		}

		/** @var string */
		$line = $iter->current();

		/** @var list<string> */
		$headers = str_getcsv(
			string: $line,
			separator: ',',
			enclosure: '"',
			escape: '\\'
		);
		while ((count($headers) === 1) && $headers[0][0] === '#') {
			$iter->next();
			if (!$iter->valid()) {
				$file->close();
				return [];
			}

			/** @var string */
			$line = $iter->current();

			/** @var list<string> */
			$headers = str_getcsv(
				string: $line,
				separator: ',',
				enclosure: '"',
				escape: '\\'
			);
		}
		$numCols = count($headers);
		$iter->next();
		while ($iter->valid()) {
			/** @var string */
			$line = $iter->current();

			$line = Safe::pregReplace('/^,/', "\x00,", $line);
			$line = Safe::pregReplace('/,$/', ",\x00", rtrim($line));
			$line = Safe::pregReplace('/,(?=,)/', ",\x00", $line);
			$row = str_getcsv(
				string: $line,
				separator: ',',
				enclosure: '"',
				escape: '\\'
			);
			if ($row === [null]) { // Skip blank lines
				$iter->next();
				continue;
			}
			for ($i = 0; $i < $numCols; $i++) {
				if ($row[$i] === "\x00") {
					$row[$i] = null;
				}
			}

			/** @var array<string,?string> */
			$result = array_combine($headers, $row);
			yield $result;
			$iter->next();
		}

		$file->close();
		return [];
	}
}
