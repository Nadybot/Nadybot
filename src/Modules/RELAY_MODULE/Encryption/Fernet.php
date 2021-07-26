<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Encryption;

use Fernet\Fernet as FernetProto;

/**
 * @Param(name='password', type='string', required=true)
 * @Param(name='salt', type='string', required=true)
 * @Param(name='hash', description='The hash algorithm to use', type='string', required=false)
 * @Param(name='iterations', type='integer', required=false)
 * @Param(name='length', type='integer', required=false)
 * @package Nadybot\Modules\RELAY_MODULE\Encryption
 */
class Fernet implements EncryptionInterface {
	protected FernetProto $fernet;

	public function __construct(string $password, string $salt, string $hashAlgo="sha256", int $iterations=10000, int $length=32) {
		$key = hash_pbkdf2($hashAlgo, $password, $salt, $iterations, $length, true);
		$base64Key = FernetProto::base64url_encode($key);
		$this->fernet = new FernetProto($base64Key);
	}

	public function encrypt(string $text): ?string {
		return $this->fernet->encode($text);
	}

	public function decrypt(string $text): ?string {
		return $this->fernet->decode($text);
	}
}
