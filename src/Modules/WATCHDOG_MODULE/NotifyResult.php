<?php declare(strict_types=1);

namespace Nadybot\Modules\WATCHDOG_MODULE;

class NotifyResult {
	public function __construct(
		public null|bool|\Socket $fd,
		public int $result,
	) {
	}
}
