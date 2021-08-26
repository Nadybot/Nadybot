<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;


/**
 * @RelayStackMember("tyr-encryption")
 * @Description('This adds tyrbot-compatible encryption to the relay-stack.')
 * @Param(name='password', description='The password to encrypt with', type='secret', required=true)
 */
class TyrEncryption extends Fernet {
	public function __construct(string $password) {
		parent::__construct($password, "tyrbot", "sha256", 10000, 32);
	}
}
