<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

/** A possible executable migration of the database */
class Migration implements Stringable {
	use StringableTrait;

	/**
	 * @param string       $filePath  The full path of the PHP file with the migration
	 * @param string       $baseName  The base class name (without namespaces)
	 * @param class-string $className The full class name of the migration
	 * @param float        $order     The order priority for this migration.
	 *                                Migrations must be executed in ascending order
	 * @param string       $module    Which module defines this migration
	 * @param bool         $shared    Is this a migration of a shared table?
	 */
	public function __construct(
		public string $filePath,
		public string $baseName,
		public string $className,
		public float $order,
		public string $module,
		public bool $shared,
	) {
	}
}
