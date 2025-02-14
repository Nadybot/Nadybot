<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use Nadybot\Core\Attributes\Hydrator\Confidential;
use Nadybot\Core\Attributes\Hydrator\{Max, Min, StrLength};

/** Credentials for a single character */
class Credentials {
	public function __construct(
		public string $login,
		#[Confidential] public string $password,
		#[StrLength(min: 4, max: 12)] public string $character,
		#[Min(4), Max(6)] public int $dimension,
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
