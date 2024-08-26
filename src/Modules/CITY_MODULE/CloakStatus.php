<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

class CloakStatus {
	public function __construct(
		public int $status,
		public string $message,
	) {
	}
}
