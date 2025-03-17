<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{preg_match, preg_match_all, preg_replace, preg_split};

use Safe\Exceptions\PcreException;

/**
 * This is a wrapper class for some functions with signatures that make it impossible
 * for static analysis to know the result. These functions will have a well-defined value
 * and throw exceptions instead of logging errors.
 */
class Safe {
	/**
	 * Searches subject for matches to
	 * pattern and replaces them with
	 * replacement.
	 *
	 * @param string $pattern The pattern to search for.
	 *
	 * Several PCRE modifiers
	 * are also available.
	 * @param string $replacement The string to replace.
	 *
	 * replacement may contain references of the form
	 * \\n or
	 * $n, with the latter form
	 * being the preferred one. Every such reference will be replaced by the text
	 * captured by the n'th parenthesized pattern.
	 * n can be from 0 to 99, and
	 * \\0 or $0 refers to the text matched
	 * by the whole pattern. Opening parentheses are counted from left to right
	 * (starting from 1) to obtain the number of the capturing sub-pattern.
	 * To use backslash in replacement, it must be doubled
	 * ("\\\\" PHP string).
	 *
	 * When working with a replacement pattern where a back-reference is
	 * immediately followed by another number (i.e.: placing a literal number
	 * immediately after a matched pattern), you cannot use the familiar
	 * \\1 notation for your back-reference.
	 * \\11, for example, would confuse
	 * preg_replace since it does not know whether you
	 * want the \\1 back-reference followed by a literal
	 * 1, or the \\11 back-reference
	 * followed by nothing.  In this case the solution is to use
	 * ${1}1.  This creates an isolated
	 * $1 back-reference, leaving the 1
	 * as a literal.
	 *
	 * When using the deprecated e modifier, this function escapes
	 * some characters (namely ', ",
	 * \ and NULL) in the strings that replace the
	 * back-references. This is done to ensure that no syntax errors arise
	 * from back-reference usage with either single or double quotes (e.g.
	 * 'strlen(\'$1\')+strlen("$2")'). Make sure you are
	 * aware of PHP's string
	 * syntax to know exactly how the interpreted string will look.
	 * @param string $subject The string to search and replace.
	 * @param int    $limit   The maximum possible replacements for each pattern in each
	 *                        subject string. Defaults to
	 *                        -1 (no limit).
	 * @param ?int   $count   If specified, this variable will be filled with the number of
	 *                        replacements done.
	 *
	 * @param-out int $count
	 *
	 * @return string pregReplace returns a string
	 *
	 * If matches are found, the new subject will
	 * be returned, otherwise subject will be
	 * returned unchanged.
	 *
	 * @throws PcreException
	 *
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress InvalidReturnType
	 */
	public static function pregReplace(string $pattern, string $replacement, string $subject, int $limit=-1, ?int &$count=null): string {
		return preg_replace($pattern, $replacement, $subject, $limit, $count);
	}

	/**
	 * Get matches for a given regular expression on a string
	 *
	 * @param string $pattern The regular expression to search for
	 * @param string $subject The string to search in
	 * @param int    $flags   Additional PCRE-flags
	 * @param int    $offset  Start searching for at the given position of `$subject`
	 *
	 * @return array<array-key,string> The matched strings as an associative array with
	 *                                 the match number and named match as key, and the
	 *                                 matching string as value
	 */
	public static function pregMatch(string $pattern, string $subject, int $flags=0, int $offset=0): array {
		$matches = [];
		if (preg_match($pattern, $subject, $matches, $flags, $offset) === 0 || !is_array($matches)) {
			return [];
		}
		return $matches;
	}

	/**
	 * Check if a string matches a given regular expression
	 *
	 * @param string $pattern The regular expression to search for
	 * @param string $subject The string to search in
	 * @param int    $flags   Additional PCRE-flags
	 * @param int    $offset  Start searching for at the given position of `$subject`
	 */
	public static function pregMatches(string $pattern, string $subject, int $flags=0, int $offset=0): bool {
		$ignore = [];
		return preg_match($pattern, $subject, $ignore, $flags, $offset) > 0;
	}

	/**
	 * Get all matches for a given regular expression on a string
	 *
	 * @param string $pattern The regular expression to search for
	 * @param string $subject The string to search in
	 * @param int    $flags   Additional PCRE-flags
	 * @param int    $offset  Start searching for at the given position of `$subject`
	 *
	 * @return array<string|int,list<string>> The matched strings as an associative array with
	 *                                        the match number and named match as key, and the
	 *                                        matching strings as values
	 *
	 * @phpstan-return array<array-key,list<string>>
	 */
	public static function pregMatchAll(string $pattern, string $subject, int $flags=0, int $offset=0): array {
		$matches = [];
		$result = preg_match_all($pattern, $subject, $matches, $flags, $offset);
		if ($result === 0 || !is_array($matches)) {
			return [];
		}
		return $matches;
	}

	/**
	 * Get all matches for a given regular expression on a string
	 *
	 * @param string $pattern The regular expression to search for
	 * @param string $subject The string to search in
	 * @param int    $flags   Additional PCRE-flags
	 * @param int    $offset  Start searching for at the given position of `$subject`
	 *
	 * @return array<string|int,list<array<int,int|string>>> The matched strings as an associative array with
	 *                                                       the match number and named match as key, and a
	 *                                                       list of arrays with the matching string at
	 *                                                       position `0` and the offset of
	 *                                                       the match in `1` as values
	 *
	 * @psalm-return array<array-key,non-empty-list<array{0:string,1:int}>>
	 */
	public static function pregMatchOffsetAll(string $pattern, string $subject, int $flags=0, int $offset=0): array {
		$matches = [];
		$result = preg_match_all($pattern, $subject, $matches, $flags | \PREG_OFFSET_CAPTURE, $offset);
		if ($result === 0 || !is_array($matches)) {
			return [];
		}
		return $matches;
	}

	/**
	 * Get all matches for a given regular expression on a string
	 *
	 * @param string $pattern The regular expression to search for
	 * @param string $subject The string to search in
	 * @param int    $flags   Additional PCRE-flags
	 * @param int    $offset  Start searching for at the given position of `$subject`
	 *
	 * @return array<string|int,string>[]
	 *
	 * @phpstan-return list<array<array-key,string>>
	 */
	public static function pregMatchOrderedAll(string $pattern, string $subject, int $flags=0, int $offset=0): array {
		$matches = [];
		$result = preg_match_all($pattern, $subject, $matches, $flags | \PREG_SET_ORDER, $offset);
		if ($result === 0 || !is_array($matches)) {
			return [];
		}

		/** @psalm-var list<string[]> $matches */

		return $matches;
	}

	/**
	 * Split the given string by a regular expression.
	 *
	 * @param string   $pattern The pattern to search for, as a string.
	 * @param string   $subject The input string.
	 * @param null|int $limit   If specified, then only substrings up to limit
	 *                          are returned with the rest of the string being placed in the last
	 *                          substring.  A limit of -1 or 0 means "no limit".
	 *                          into subject at offset 1.
	 *
	 * @return string[] Returns an array containing substrings of subject
	 *                  split along boundaries matched by pattern.
	 *
	 * @psalm-return non-empty-list<string>
	 *
	 * @throws PcreException
	 */
	public static function pregSplit(string $pattern, string $subject, ?int $limit=-1): array {
		/**
		 * @var string[]
		 *
		 * @psalm-var non-empty-list<string>
		 */
		$result = preg_split($pattern, $subject, $limit);
		return $result;
	}

	/**
	 * Remove all null-values from a given array
	 *
	 * @template TKey as array-key
	 * @template TValue
	 *
	 * @param array<TKey, null|TValue> $values
	 *
	 * @return array<TKey, TValue>
	 */
	public static function removeNull(array $values): array {
		$result = array_filter($values, static fn (mixed $value): bool => !is_null($value));
		return $result;
	}

	/**
	 * Perform a regular expression search and replace using a callback
	 *
	 * @param string|list<string> $pattern
	 * @param string|list<string> $subject
	 * @param int                 $limit   The maximum possible replacements for each pattern in each subject string. Defaults to -1 (no limit).
	 * @param ?int                $count   If specified, this variable will be filled with the number of replacements done.
	 *
	 * @param-out int $count   If specified, this variable will be filled with the number of replacements done.
	 *
	 * @psalm-param int-mask<\PREG_OFFSET_CAPTURE,\PREG_UNMATCHED_AS_NULL> $flags
	 *
	 * @return ($subject is array ? list<string> : string) preg_replace_callback returns an array if the subject parameter is an array, or a string otherwise.
	 *
	 * @throws PcreException on invalid regexp
	 *
	 * @psalm-suppress ReferenceConstraintViolation
	 * @psalm-suppress ArgumentTypeCoercion
	 */
	public static function pregReplaceCallback(
		array|string $pattern,
		callable $callback,
		array|string $subject,
		int $limit=-1,
		?int &$count=null,
		int $flags=0
	): array|string {
		error_clear_last();

		/** @phpstan-ignore theCodingMachineSafe.function */
		$result = preg_replace_callback($pattern, $callback, $subject, $limit, $count, $flags);
		if (!isset($result)) {
			throw PcreException::createFromPhpError();
		}
		return $result;
	}

	/** Wrap a call to $closure so that any error is converted into an exception */
	public static function exceptionWrapper(
		callable $closure,
		mixed ...$args
	): mixed {
		set_error_handler(
			static function (int $code, string $message, ?string $file, ?int $line): void {
				throw new \ErrorException(
					message: $message,
					severity: $code,
					filename: $file,
					line: $line,
				);
			}
		);
		try {
			return $closure(...$args);
		} finally {
			restore_error_handler();
		}
	}
}
