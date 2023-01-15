<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class UpdateNotification {
	public function __construct(
		public string $message,
		public ?string $minVersion=null,
		public ?string $maxVersion=null,
	) {
	}
}
