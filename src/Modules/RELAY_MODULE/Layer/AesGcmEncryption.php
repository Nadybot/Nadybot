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
		$ivLength = $this->ivLength;
		if (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available()) {
			$rawString = sodium_base642bin($text, SODIUM_BASE64_VARIANT_ORIGINAL);
		} else {
			$rawString = base64_decode($text);
		}
		$iv = substr($rawString, 0, $ivLength);
		$tag = substr($rawString, $ivLength, $tagLength = 16);
		$ciphertextRaw = substr($rawString, $ivLength + $tagLength);
		if (strlen($ciphertextRaw) === 0) {
			return null;
		}
		if (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available()) {
			$originalText = sodium_crypto_aead_aes256gcm_decrypt($ciphertextRaw.$tag, "", $iv, $this->password);
		} else {
			$originalText = openssl_decrypt($ciphertextRaw, static::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
		}
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
		if (function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available()) {
			$enc = sodium_crypto_aead_aes256gcm_encrypt($text, "", $iv, $this->password);
			$ciphertextRaw = substr($enc, 0, -16);
			$tag = substr($enc, -16);
			return sodium_bin2base64($iv . $tag . $ciphertextRaw, SODIUM_BASE64_VARIANT_ORIGINAL);
		}
		$ciphertextRaw = openssl_encrypt($text, static::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
		return base64_encode($iv . $tag . $ciphertextRaw);
	}
}
