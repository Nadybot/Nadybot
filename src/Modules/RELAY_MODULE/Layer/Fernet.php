<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Fernet\Fernet as FernetProto;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;

/**
 * @RelayStackMember("fernet-encryption")
 * @Description('This adds fernet-based encryption to the relay-stack.
 *	You can configure all parameters of the encryption via options.
 *	Encryption only works if all relay-parties use the same
 *	encryption parameters!
 *	Fernet guarantees that the data you send is unaltered')
 * @Param(name='password', description='The password to derive our encryption key from', type='string', required=true)
 * @Param(name='salt', description='The salt to add to the password', type='string', required=true)
 * @Param(name='hash', description='The hash algorithm to use', type='string', required=false)
 * @Param(name='iterations', description='Number of iterations', type='integer', required=false)
 * @package Nadybot\Modules\RELAY_MODULE\Encryption
 */
class Fernet implements RelayLayerInterface {
	protected FernetProto $fernet;

	protected Relay $relay;

	public function __construct(string $password, string $salt, string $hashAlgo="sha256", int $iterations=10000) {
		$key = hash_pbkdf2($hashAlgo, $password, $salt, $iterations, 32, true);
		$base64Key = FernetProto::base64url_encode($key);
		$this->fernet = new FernetProto($base64Key);
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function init(callable $callback): array {
		return [];
	}

	public function deinit(callable $callback): array {
		return [];
	}

	public function send(array $packets): array {
		return array_map([$this->fernet, "encode"], $packets);
	}

	public function receive(string $text): ?string {
		return $this->fernet->decode($text);
	}
}
