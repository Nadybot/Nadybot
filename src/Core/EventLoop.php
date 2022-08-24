<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;

class EventLoop {
	/** @var array<int,array{callable,string}> */
	protected static array $callbacks = [];

	/** @deprecated 6.1.0 */
	public function execSingleLoop(): void {
	}

	/** @deprecated version */
	public static function add(callable $callback): int {
		$i = 0;
		while ($i < count(static::$callbacks)) {
			if (!array_key_exists($i, static::$callbacks)) {
				break;
			}
			$i++;
		}
		$handle = Loop::repeat(100, $callback);
		static::$callbacks[$i] = [$callback, $handle];
		return $i;
	}

	/** @deprecated version */
	public static function rem(int $i): bool {
		if (!array_key_exists($i, static::$callbacks)) {
			return false;
		}
		[$callback, $handle] = static::$callbacks[$i];
		Loop::cancel($handle);
		unset(static::$callbacks[$i]);
		return true;
	}
}
