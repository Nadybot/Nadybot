<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Fernet\Fernet as FernetProto;
use Nadybot\Modules\RELAY_MODULE\RelayStackMember;

/**
 * @RelayStackMember("fernet-encryption")
 * @Description('This adds fernet-based encryption to the relay-stack.
 *	You can configure all parameters of the encryption via options.
 *	Encryption only works if all relay-parties use the same
 *	encryption parameters!')
 * @Param(name='password', description='The password to encrypt with', type='string', required=true)
 * @Param(name='salt', description='The salt to use for the encryption', type='string', required=true)
 * @Param(name='hash', description='The hash algorithm to use', type='string', required=false)
 * @Param(name='iterations', description='Number of iterations', type='integer', required=false)
 * @Param(name='length', description='No idea what it does', type='integer', required=false)
 * @package Nadybot\Modules\RELAY_MODULE\Encryption
 */
class Fernet implements RelayStackMember {
	protected FernetProto $fernet;

	public function __construct(string $password, string $salt, string $hashAlgo="sha256", int $iterations=10000, int $length=32) {
		$key = hash_pbkdf2($hashAlgo, $password, $salt, $iterations, $length, true);
		$base64Key = FernetProto::base64url_encode($key);
		$this->fernet = new FernetProto($base64Key);
	}

	public function init(?object $previous, callable $callback): void {
		$callback();
	}

	public function send(array $packets): array {
		return array_map([$this->fernet, "encode"], $packets);
	}

	public function receive(string $text): ?string {
		return $this->fernet->decode($text);
	}
}
