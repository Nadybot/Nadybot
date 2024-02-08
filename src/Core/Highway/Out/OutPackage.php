<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

use Nadybot\Core\Highway\Package;

class OutPackage extends Package {
	private static int $pkgCounter = 0;

	public function __construct(
		public null|int|string $id,
	) {
		$this->id ??= ++self::$pkgCounter;
	}
}
