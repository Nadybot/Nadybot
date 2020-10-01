<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

class CallerList {
	/**
	 * Name of this list of callers, e.g. "RI1", "east", or empty string if default
	 */
	public string $name;

	/**
	 * Names of the players who are callers
	 * @var string[]
	 */
	public array $callers = [];
}
