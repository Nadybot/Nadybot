<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Layer;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Types\ParamType;

/** This adds tyrbot-compatible encryption to the relay-stack. */
#[NCA\RelayStackMember(name: 'tyr-encryption')]
class TyrEncryption extends Fernet {
	/** @param string $password The password to encrypt with */
	public function __construct(
		#[NCA\Param(type: ParamType::Secret)] string $password
	) {
		parent::__construct($password, 'tyrbot', 'sha256', 10_000);
	}
}
