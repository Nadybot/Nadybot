<?php declare(strict_types=1);

namespace Nadybot\Core\CSV;

use function Amp\ByteStream\splitLines;

use IteratorIterator;
use Nadybot\Core\{Filesystem, Safe};

class Reader {
	public function __construct(
		private string $file,
		private Filesystem $filesystem,
	) {
	}

	/**
	 * Get a line from the CSV as hash
	 *
	 * @return array<mixed>|\Generator<array<string,mixed>>
	 */
	public function items(): iterable {
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
		$line = $iter->current();

		/** @var string[] */
		$headers = str_getcsv($line);
		while ((count($headers) === 1) && is_string($headers[0]) && $headers[0][0] === '#') {
			$iter->next();
			if (!$iter->valid()) {
				$file->close();
				return [];
			}
			$line = $iter->current();

			/** @var string[] */
			$headers = str_getcsv($line);
		}
		$numCols = count($headers);
		$iter->next();
		while ($iter->valid()) {
			$line = $iter->current();
			$line = Safe::pregReplace('/^,/', "\x00,", $line);
			$line = Safe::pregReplace('/,$/', ",\x00", rtrim($line));
			$line = Safe::pregReplace('/,(?=,)/', ",\x00", $line);
			$row = str_getcsv($line);
			if ($row === [null]) { // Skip blank lines
				$iter->next();
				continue;
			}
			for ($i = 0; $i < $numCols; $i++) {
				if ($row[$i] === "\x00") {
					$row[$i] = null;
				}
			}

			yield array_combine($headers, $row);
			$iter->next();
		}

		$file->close();
		return [];
	}
}
