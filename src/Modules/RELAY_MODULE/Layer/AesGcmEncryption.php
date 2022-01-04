<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayLayerInterface;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;

/**
 *	on every message, so even if one was cracked, the rest is still secure.
 *	This is state-of-the-art cryptography and proven secure.
 *	Encryption only works if all parties use the same password!')
 */
#[
	NCA\RelayStackMember(
		name: "aes-gcm-encryption",
		description:
			"This adds 256 bit AES encryption with Galois/Counter mode to the relay-stack.\n".
			"It guarantees that the data was not tampered with, and rotates the salt(iv)\n".
			"on every message, so even if one was cracked, the rest is still secure.\n".
			"This is state-of-the-art cryptography and proven secure.\n".
			"Encryption only works if all parties use the same password!"
	),
	NCA\Param(
		name: "password",
		type: "secret",
		description: "The password to derive our encryption key from",
		required: true
	)
]
class AesGcmEncryption implements RelayLayerInterface {
	public const CIPHER = "aes-256-gcm";
	protected string $password;
	protected int $ivLength;

	protected Relay $relay;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public function __construct(string $password) {
		$this->password = \Safe\openssl_digest($password, 'SHA256', true);
		$ivLength = \Safe\openssl_cipher_iv_length(static::CIPHER);
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

	public function send(array $data): array {
		return array_map([$this, "encode"], $data);
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
		$this->logger->debug("Decoding AES-GCM encrypted message for relay {relay}", [
			"relay" => $this->relay->getName(),
			"data" => $text,
		]);
		$ivLength = $this->ivLength;
		if (
			function_exists('sodium_crypto_aead_aes256gcm_is_available')
			&& sodium_crypto_aead_aes256gcm_is_available()
			&& defined("SODIUM_BASE64_VARIANT_ORIGINAL")
		) {
			$rawString = sodium_base642bin($text, SODIUM_BASE64_VARIANT_ORIGINAL);
		} else {
			$rawString = \Safe\base64_decode($text);
		}
		$iv = substr($rawString, 0, $ivLength);
		$tag = substr($rawString, $ivLength, $tagLength = 16);
		$ciphertextRaw = substr($rawString, $ivLength + $tagLength);
		if (strlen($ciphertextRaw) === 0) {
			$this->logger->debug("The encrypted data was empty for relay {relay}", [
				"relay" => $this->relay->getName(),
			]);
			return null;
		}
		try {
			$useSodium = function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available();
			$originalText = $useSodium
				? \Safe\sodium_crypto_aead_aes256gcm_decrypt($ciphertextRaw.$tag, "", $iv, $this->password)
				: \Safe\openssl_decrypt($ciphertextRaw, self::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
		} catch (Exception $e) {
			$this->logger->info("Unable to decode the AES-GCM encrypted message for relay {relay}", [
				"relay" => $this->relay->getName(),
				"exception" => $e,
			]);
			return null;
		}
		$this->logger->debug("Successfully decoded AES-GCM encrypted message for relay {relay}", [
			"relay" => $this->relay->getName(),
			"decrypted" => $originalText,
		]);
		return $originalText;
	}

	public function encode(string $text): string {
		$this->logger->debug("Encoding message for relay {relay} with AES-GCM", [
			"relay" => $this->relay->getName(),
			"data" => $text,
		]);
		$ivLength = $this->ivLength;
		[$micro, $secs] = explode(" ", microtime());
		$iv = \Safe\pack("NN", $secs, (float)$micro*100000000);
		$iv .= random_bytes($ivLength - strlen($iv));
		if (
			function_exists('sodium_crypto_aead_aes256gcm_is_available')
			&& sodium_crypto_aead_aes256gcm_is_available()
			&& defined("SODIUM_BASE64_VARIANT_ORIGINAL")
		) {
			$enc = sodium_crypto_aead_aes256gcm_encrypt($text, "", $iv, $this->password);
			$ciphertextRaw = substr($enc, 0, -16);
			$tag = substr($enc, -16);
			$encrypted = sodium_bin2base64($iv . $tag . $ciphertextRaw, SODIUM_BASE64_VARIANT_ORIGINAL);
		} else {
			$ciphertextRaw = \Safe\openssl_encrypt($text, static::CIPHER, $this->password, OPENSSL_RAW_DATA, $iv, $tag);
			$encrypted = base64_encode($iv . $tag . $ciphertextRaw);
		}
		$this->logger->debug("Successfully encoded message for relay {relay} with AES-GCM", [
			"relay" => $this->relay->getName(),
			"encrypted" => $encrypted,
		]);
		return $encrypted;
	}
}
