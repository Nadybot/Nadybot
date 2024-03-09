<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Fernet\Fernet as FernetProto;
use Nadybot\Core\{Attributes as NCA};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayLayerInterface,
	RelayMessage,
};

/**
 * @package Nadybot\Modules\RELAY_MODULE\Encryption
 */
#[
	NCA\RelayStackMember(
		name: "fernet-encryption",
		description: "This adds fernet-based 128 bit AES encryption to the relay-stack.\n".
			"You can configure all parameters of the encryption key generation via options.\n".
			"Encryption layers only work if all relay-parties use the same encryption parameters!\n".
			"Fernet guarantees that the data you send is unaltered"
	),
	NCA\Param(
		name: "password",
		type: "secret",
		description: "The password to derive our encryption key from",
		required: true
	),
	NCA\Param(
		name: "salt",
		type: "secret",
		description: "The salt to add to the password",
		required: true
	),
	NCA\Param(
		name: "hash",
		type: "string",
		description: "The hash algorithm to ensure messages are unaltered",
		required: false
	),
	NCA\Param(
		name: "iterations",
		type: "integer",
		description: "Number of iterations",
		required: false
	)
]
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
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function send(array $data): array {
		return array_map([$this->fernet, "encode"], $data);
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		foreach ($msg->packages as &$text) {
			$text = $this->fernet->decode($text);
		}
		return $msg;
	}
}
