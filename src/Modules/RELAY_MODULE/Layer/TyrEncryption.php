<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\Attributes as NCA;


#[
	NCA\RelayStackMember(
		name: "tyr-encryption",
		description: "This adds tyrbot-compatible encryption to the relay-stack."
	),
	NCA\Param(
		name: "password",
		type: "secret",
		description: "The password to encrypt with",
		required: true
	)
]
class TyrEncryption extends Fernet {
	public function __construct(string $password) {
		parent::__construct($password, "tyrbot", "sha256", 10000);
	}
}
