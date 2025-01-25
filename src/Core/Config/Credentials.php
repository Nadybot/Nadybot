<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Confidential;

/** Credentials for a single character */
class Credentials {
	public function __construct(
		public string $login,
		#[Confidential] public string $password,
		public string $character,
		public int $dimension,
		#[Confidential] public ?string $webLogin=null,
		#[Confidential] public ?string $webPassword=null,
	) {
		$this->character = ucfirst(strtolower($this->character));
		if ($this->webLogin === '') {
			$this->webLogin = null;
		}
		if ($this->webPassword === '') {
			$this->webPassword = null;
		}
	}
}
