<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use function Safe\json_encode;

use Safe\Exceptions\JsonException;

/**
 * This represents a value for logging that's only calculated if this log-line is
 * actually logged, in order to allow complicated debugging values
 */
class LazyValue implements Loggable {
	/** @var mixed[] */
	private array $args;
	private ?string $cached = null;

	public function __construct(private \Closure $value, mixed ...$args) {
		$this->args = $args;
	}

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
