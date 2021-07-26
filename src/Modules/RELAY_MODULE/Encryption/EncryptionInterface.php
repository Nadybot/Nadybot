<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Encryption;

interface EncryptionInterface {
	/**
	 * Encrypt the given string with the configured password and other
	 * parameters or return null if there was an error
	 */
	public function encrypt(string $text): ?string;

	/**
	 * Decrypt the given string with the configured password and other
	 * parameters or return null if there was an error
	 */
	public function decrypt(string $text): ?string;
}
