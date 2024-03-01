<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

class Credentials {
	public function __construct(
		public string $login,
		public string $password,
		public string $character,
		public int $dimension,
	) {
		$this->character = ucfirst(strtolower($this->character));
	}
}
