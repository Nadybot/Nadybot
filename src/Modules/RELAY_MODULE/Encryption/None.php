<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Encryption;

class None implements EncryptionInterface {
	public function encrypt(string $text): ?string {
		return $text;
	}

	public function decrypt(string $text): ?string {
		return $text;
	}
}
