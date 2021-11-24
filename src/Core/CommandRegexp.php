<?php declare(strict_types=1);

namespace Nadybot\Core;

class CommandRegexp {
	public string $match;
	public ?string $variadicMatch=null;

	public function __construct(string $match, ?string $variadicMatch=null) {
		$this->match = $match;
		$this->variadicMatch = $variadicMatch;
	}
}
