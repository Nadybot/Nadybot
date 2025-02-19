<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use function Safe\json_encode;

use Safe\Exceptions\JsonException;

/**
 * This represents a value for logging that's only calculated if this log-line is
 * actually logged, in order to allow complicated debugging values that don't slow
 * down the bot when they're not being logged.
 */
class LazyValue implements Loggable {
	/**
	 * The arguments to pass to the closure when called
	 *
	 * @var mixed[]
	 */
	private array $args;

	/**
	 * This is the cached string representation of the logged value,
	 * or null if not determined yet
	 */
	private ?string $cached = null;

	/**
	 * @param \Closure $value   The closure to call to get the value
	 * @param mixed    ...$args The arguments to pass to the closure
	 */
	public function __construct(private \Closure $value, mixed ...$args) {
		$this->args = $args;
	}

	/** Get the textual representation of this value to log */
	public function toLog(): string {
		if (isset($this->cached)) {
			return $this->cached;
		}
		$result = call_user_func_array($this->value, $this->args);
		try {
			$result = json_encode(
				$result,
				\JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE|\JSON_INVALID_UTF8_SUBSTITUTE
			);
		} catch (JsonException) {
			$result = '<unknown>';
		}
		return $this->cached = $result;
	}
}
