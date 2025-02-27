<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

/** This represents a structured command handler */
class CmdCallHandler implements Stringable {
	public function __construct(
		public string $className,
		public string $method,
		public int $line,
	) {
	}

	public function __toString(): string {
		return "{$this->className}.{$this->method}.{$this->line}";
	}

	/**
	 * Parse a command handler from a single string in the
	 * format `<class name>.<method name>:<line number>`
	 *
	 * @param string $handler The handler to parse
	 */
	public static function fromString(string $handler): self {
		[$className, $method] = explode('.', $handler);
		[$method, $line] = explode(':', $method);
		return new self(
			className: $className,
			method: $method,
			line: (int)$line,
		);
	}

	/**
	 * Compare our handler against another one for sorting
	 * Sorting is done by class name and then  line number ascending,
	 * so functions higher up in the file are preferred
	 */
	public function compare(self $other): int {
		$firstCmp = strcmp($this->className, $other->className);
		return ($firstCmp !== 0) ? $firstCmp : $this->line <=> $other->line;
	}
}
