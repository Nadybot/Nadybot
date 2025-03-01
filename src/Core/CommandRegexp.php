<?php declare(strict_types=1);

namespace Nadybot\Core;

/** This is a regular expression to match a single parameter of a command */
class CommandRegexp {
	/**
	 * @param string      $match         The regular expression to match
	 * @param null|string $variadicMatch Whether the regexp can match multiple
	 *                                   times, because it's a variadic command parameter.
	 */
	public function __construct(
		public string $match,
		public ?string $variadicMatch=null
	) {
	}
}
