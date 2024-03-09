<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class PrivLockStats implements GaugeProvider {
	#[NCA\Inject]
	private PrivateChannelController $privChan;

	public function getValue(): float {
		return $this->privChan->isLocked() ? 1 : 0;
	}

	public function getTags(): array {
		return ["type" => "private_channel_locked"];
	}
}
