<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;

/** A listener that's being called if a bot setting changed */
class ChangeListener {
	/**
	 * @param \Closure $callback The closure to execute
	 * @param mixed    $data     Optional argument to the `$callback`
	 */
	public function __construct(
		public Closure $callback,
		public mixed $data,
	) {
	}
}
