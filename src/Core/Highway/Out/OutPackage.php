<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

use Nadybot\Core\Highway\Package;

class OutPackage extends Package {
	public function __construct(
		public string $type,
		public ?int $id=null,
	) {
	}
}
