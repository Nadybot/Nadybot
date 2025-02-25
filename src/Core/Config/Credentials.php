<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use AO\Utils;
use Nadybot\Core\Attributes\Hydrator\Confidential;
use Nadybot\Core\Attributes\Hydrator\{Max, Min, StrLength};

/** Credentials for a single character */
class Credentials {
	/**
	 * @param string      $login       The Funcom login for the account
	 * @param string      $password    The password for this account
	 * @param string      $character   The character name (exactly as it appears,
	 *                                 first letter upper case)
	 * @param int         $dimension   Which dimension to use
	 *                                 * 4: test live
	 *                                 * 5: Rubi-Ka
	 *                                 * 6: RK19
	 * @param null|string $webLogin    If you manage your accounts from a master account,
	 *                                 then this is the login to https://account.anarchy-online.com
	 *                                 that manages all the accounts.
	 *                                 Only needs to be set if this account is managed by
	 *                                 another login
	 * @param null|string $webPassword The password for $webLogin
	 */
	public function __construct(
		public string $login,
		#[Confidential] public string $password,
		#[StrLength(min: 4, max: 12)] public string $character,
		#[Min(4), Max(6)] public int $dimension,
		#[Confidential] public ?string $webLogin=null,
		#[Confidential] public ?string $webPassword=null,
	) {
		$this->character = Utils::normalizeCharacter($this->character);
		if ($this->webLogin === '') {
			$this->webLogin = null;
		}
		if ($this->webPassword === '') {
			$this->webPassword = null;
		}
	}
}
