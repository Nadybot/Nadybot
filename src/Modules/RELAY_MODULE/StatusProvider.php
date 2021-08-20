<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

interface StatusProvider {
	public function getStatus(): RelayStatus;
}
