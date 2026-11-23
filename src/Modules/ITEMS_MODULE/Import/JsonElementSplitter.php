<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE\Import;

use function Safe\{json_decode, preg_match};

use Generator;
use JsonException;
use Nadybot\Modules\ITEMS_MODULE\Exceptions\ItemImportException;

/**
 * Splits a JSON document of the form {"key": value, ...} into its elements
 * without decoding the whole document at once
 *
 * Top-level scalars are returned as a whole. For top-level arrays and
 * objects, every single element is returned on its own, together with its key
 * when the container is an object. The raw JSON of each part can then be
 * decoded individually, so memory usage only depends on the largest element.
 *
 * The input can be fed in chunks of arbitrary size.
 */
final class JsonElementSplitter {
	private const START = 0;
	private const TOP_KEY = 1;
	private const TOP_COLON = 2;
	private const TOP_VALUE = 3;
	private const TOP_SCALAR = 4;
	private const SEC_ITEM = 5;
	private const SEC_COLON = 6;
	private const SEC_VALUE = 7;
	private const SEC_SCALAR = 8;
	private const ELEMENT = 9;
	private const TOP_NEXT = 10;
	private const SEC_NEXT = 11;
	private const END = 12;

	/**
	 * A complete JSON array or object, starting at the given offset
	 *
	 * Strings are skipped as a whole, so brackets inside them do not count.
	 * The match fails if the container is not complete yet.
	 */
	private const CONTAINER = '/\G(?<v>\{(?:[^{}\[\]"]++|"(?:[^"\\\\]++|\\\\.)*+"|(?&v))*+\}'.
		'|\[(?:[^{}\[\]"]++|"(?:[^"\\\\]++|\\\\.)*+"|(?&v))*+\])/s';

	/** If an element is not complete after this many bytes, the document is invalid */
	private const MAX_ELEMENT_SIZE = 16 * 1_024 * 1_024;

	private string $buf = '';
	private int $pos = 0;
	private int $state = self::START;
	private bool $inString = false;

	/** Start offset in $buf of the token being read, -1 if none */
	private int $tokenStart = -1;
	private string $section = '';
	private bool $sectionIsObject = false;
	private ?string $key = null;

	/**
	 * Feed the next chunk of the document
	 *
	 * @return Generator<int,array{string,?string,string}> [top-level key, element key, raw JSON]
	 *
	 * @throws ItemImportException on syntax errors
	 */
	public function feed(string $chunk): Generator {
		$keepFrom = $this->tokenStart >= 0 ? $this->tokenStart : $this->pos;
		if ($keepFrom > 0) {
			$this->buf = substr($this->buf, $keepFrom);
			$this->pos -= $keepFrom;
			if ($this->tokenStart >= 0) {
				$this->tokenStart -= $keepFrom;
			}
		}
		$this->buf .= $chunk;
		$buf = $this->buf;
		$len = strlen($buf);
		$pos = $this->pos;

		while ($pos < $len) {
			if ($this->inString) {
				$pos += strcspn($buf, '"\\', $pos);
				if ($pos >= $len) {
					break;
				}
				if ($buf[$pos] === '\\') {
					if ($pos + 1 >= $len) {
						break;
					}
					$pos += 2;
					continue;
				}
				$pos++;
				$this->inString = false;
				if ($this->state === self::TOP_KEY) {
					$this->section = $this->decodeKey($buf, $pos);
					$this->state = self::TOP_COLON;
				} elseif ($this->state === self::SEC_ITEM) {
					$this->key = $this->decodeKey($buf, $pos);
					$this->state = self::SEC_COLON;
				}
				continue;
			}
			if ($this->state === self::ELEMENT) {
				$element = null;
				$matches = [];
				if (preg_match(self::CONTAINER, $buf, $matches, 0, $this->tokenStart) === 1) {
					$element = $matches[0] ?? null;
				}
				if (!is_string($element)) {
					if ($len - $this->tokenStart > self::MAX_ELEMENT_SIZE) {
						throw new ItemImportException("Invalid or too large element at offset {$this->tokenStart}");
					}
					break;
				}
				$pos = $this->tokenStart + strlen($element);
				yield [$this->section, $this->key, $element];
				$this->tokenStart = -1;
				$this->key = null;
				$this->state = self::SEC_NEXT;
				continue;
			}
			if ($this->state === self::TOP_SCALAR || $this->state === self::SEC_SCALAR) {
				$pos += strcspn($buf, '",}]', $pos);
				if ($pos >= $len) {
					break;
				}
				if ($buf[$pos] === '"') {
					$pos++;
					$this->inString = true;
					continue;
				}
				$raw = trim(substr($buf, $this->tokenStart, $pos - $this->tokenStart));
				$this->tokenStart = -1;
				if ($this->state === self::TOP_SCALAR) {
					yield [$this->section, null, $raw];
					$this->state = self::TOP_NEXT;
				} else {
					yield [$this->section, $this->key, $raw];
					$this->key = null;
					$this->state = self::SEC_NEXT;
				}
				continue;
			}

			$char = $buf[$pos];
			if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
				$pos++;
				continue;
			}
			switch ($this->state) {
				case self::START:
					$this->expect($char, '{', $pos);
					$this->state = self::TOP_KEY;
					break;
				case self::TOP_KEY:
					if ($char === '}') {
						$this->state = self::END;
						break;
					}
					$this->expect($char, '"', $pos);
					$this->tokenStart = $pos;
					$this->inString = true;
					break;
				case self::TOP_COLON:
					$this->expect($char, ':', $pos);
					$this->state = self::TOP_VALUE;
					break;
				case self::TOP_VALUE:
					if ($char === '{' || $char === '[') {
						$this->sectionIsObject = $char === '{';
						$this->state = self::SEC_ITEM;
						break;
					}
					$this->tokenStart = $pos;
					$this->state = self::TOP_SCALAR;
					if ($char === '"') {
						$this->inString = true;
					}
					break;
				case self::TOP_NEXT:
					if ($char === '}') {
						$this->state = self::END;
						break;
					}
					$this->expect($char, ',', $pos);
					$this->state = self::TOP_KEY;
					break;
				case self::SEC_ITEM:
					if ($char === ($this->sectionIsObject ? '}' : ']')) {
						$this->state = self::TOP_NEXT;
						break;
					}
					if ($this->sectionIsObject) {
						$this->expect($char, '"', $pos);
						$this->tokenStart = $pos;
						$this->inString = true;
						break;
					}
					$this->startValue($char, $pos);
					break;
				case self::SEC_COLON:
					$this->expect($char, ':', $pos);
					$this->state = self::SEC_VALUE;
					break;
				case self::SEC_VALUE:
					$this->startValue($char, $pos);
					break;
				case self::SEC_NEXT:
					if ($char === ($this->sectionIsObject ? '}' : ']')) {
						$this->state = self::TOP_NEXT;
						break;
					}
					$this->expect($char, ',', $pos);
					$this->state = self::SEC_ITEM;
					break;
				case self::END:
					throw new ItemImportException("Unexpected data after the end of the document at offset {$pos}");
			}
			$pos++;
		}
		$this->pos = $pos;
	}

	/**
	 * Check that the whole document has been read
	 *
	 * @throws ItemImportException if the document is incomplete
	 */
	public function finish(): void {
		if ($this->state !== self::END) {
			throw new ItemImportException('The document ended prematurely');
		}
	}

	/**
	 * Start reading an element whose first character is at $pos
	 *
	 * Containers are matched as a whole from $pos, the scan of scalars
	 * continues after their first character.
	 */
	private function startValue(string $char, int $pos): void {
		$this->tokenStart = $pos;
		if ($char === '{' || $char === '[') {
			$this->state = self::ELEMENT;
			return;
		}
		$this->state = self::SEC_SCALAR;
		$this->inString = $char === '"';
	}

	private function decodeKey(string $buf, int $end): string {
		try {
			$key = json_decode(substr($buf, $this->tokenStart, $end - $this->tokenStart));
		} catch (JsonException $e) {
			throw new ItemImportException('Invalid key in the document', 0, $e);
		}
		$this->tokenStart = -1;
		if (!is_string($key)) {
			throw new ItemImportException('Invalid key in the document');
		}
		return $key;
	}

	private function expect(string $char, string $expected, int $pos): void {
		if ($char !== $expected) {
			throw new ItemImportException("Expected '{$expected}', found '{$char}' at offset {$pos}");
		}
	}
}
