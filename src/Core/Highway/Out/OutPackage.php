<?php declare(strict_types=1);

namespace Nadybot\Core\Highway\Out;

use Nadybot\Core\Highway\Package;

/** A generic class for all outgoing packages */
class OutPackage extends Package {
	/** The total number of outgoing messages created so far */
	private static int $pkgCounter = 0;

	/**
	 * @param string          $type Type of the package
	 * @param null|int|string $id   ID of this message. Will be given back in replies.
	 *                              (highway 0.2 only, auto-generated on `null`)
	 */
	public function __construct(
		string $type,
		public null|int|string $id,
	) {
		parent::__construct($type);
		$this->id ??= ++self::$pkgCounter;
	}
}
