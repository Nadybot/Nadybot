<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Exception;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;

/**
 * @RelayStackMember("aes-gcm-encryption")
 * @Description('This adds 256 bit AES encryption with Galois/Counter mode to the relay-stack.
 *	It guarantees that the data was not tampered with, and rotates the salt(iv)
 *	on every message, so even if one was cracked, the rest is still secure.
 *	This is state-of-the-art cryptography and proven secure.
 *	Encryption only works if all parties use the same password!')
 * @Param(name='password', description='The password to derive our encryption key from', type='secret', required=true)
 */
class AesGcmEncryption implements RelayLayerInterface {
	public const CIPHER = "aes-256-gcm";
	protected string $password;
	protected int $ivLength;

	protected Relay $relay;

	/** @Logger */
	public LoggerWrapper $logger;

	public function __construct(string $password) {
		$this->password = openssl_digest($password, 'SHA256', true);
		$ivLength = openssl_cipher_iv_length(static::CIPHER);
		if ($ivLength === false) {
			throw new Exception(
				"Your PHP installation does not support ".
				"<highlight>" . static::CIPHER . "<end> encryption."
			);
		}
		$this->ivLength = $ivLength;
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

	public function send(array $packets): array {
		return array_map([$this, "encode"], $packets);
	}

	public function receive(RelayMessage $msg): ?RelayMessage {
		$result = [];
		foreach ($msg->packages as $text) {
			$text = $this->decode($text);
			if (!isset($text)) {
				continue;
			}
			$result []= $text;
		}
		$msg->packages = $result;
		return $msg;
	}

	protected function decode(string $text): ?string {
		$rawString = base64_decode($text);
		$ivLength = $this->ivLength;
		$iv = substr($rawString, 0, $ivLength);
		$tag = substr($rawString, $ivLength, $tagLength = 16);
		$ciphertextRaw = substr($rawString, $ivLength + $tagLength);
		if (strlen($ciphertextRaw) === 0) {
			return null;
		}
		$originalText = openssl_decrypt($ciphertextRaw, static::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
		if ($originalText === false) {
			return null;
		}
		return $originalText;
	}

	public function encode(string $text): string {
		$ivLength = $this->ivLength;
		[$micro, $secs] = explode(" ", microtime());
		$iv = pack("NN", $secs, $micro*100000000);
		$iv .= random_bytes($ivLength - strlen($iv));
		$ciphertextRaw = openssl_encrypt($text, static::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
		return base64_encode($iv . $tag . $ciphertextRaw);
	}
}
