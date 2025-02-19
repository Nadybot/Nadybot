<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Fernet\Fernet as FernetProto;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Types\ParamType;
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayLayerInterface,
	RelayMessage,
};

/**
 * This adds fernet-based 128 bit AES encryption to the relay-stack.
 * You can configure all parameters of the encryption key generation via options.
 * Encryption layers only work if all relay-parties use the same encryption parameters!
 * Fernet guarantees that the data you send is unaltered.
 */
#[NCA\RelayStackMember(name: 'fernet-encryption')]
class Fernet implements RelayLayerInterface {
	protected FernetProto $fernet;

	protected Relay $relay;

	/**
	 * @param string $password   The password to derive our encryption key from
	 * @param string $salt       The salt to add to the password
	 * @param string $hashAlgo   The hash algorithm to ensure messages are unaltered
	 * @param int    $iterations Number of iterations
	 *
	 * @psalm-param non-falsy-string $hashAlgo
	 * @psalm-param positive-int     $iterations
	 */
	public function __construct(
		#[NCA\Param(type: ParamType::Secret)] string $password,
		#[NCA\Param(type: ParamType::Secret)] string $salt,
		#[NCA\Param(name: 'hash')] string $hashAlgo='sha256',
		#[NCA\Param] int $iterations=10_000,
	) {
		$key = hash_pbkdf2($hashAlgo, $password, $salt, $iterations, 32, true);
		$base64Key = FernetProto::base64url_encode($key);
		$this->fernet = new FernetProto($base64Key);
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function send(array $data): array {
		return array_map([$this->fernet, 'encode'], $data);
	}

	public function receive(RelayMessage $msg): RelayMessage {
		foreach ($msg->packages as &$text) {
			$text = $this->fernet->decode($text);
		}
		return $msg;
	}
}
