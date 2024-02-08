<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\In;

use Nadybot\Core\Highway\Package;

class InPackage extends Package {
	public function __construct(
		public string $type,
	) {
	}
}
