<?php declare(strict_types=1);

namespace Nadybot\Core;

/** This data structure holds information about classes with an #[Instance] attribute */
class ClassInstance {
	/**
	 * @param string       $name      Name of the instance, as stored in the Registry
	 * @param class-string $className The full class name
	 * @param bool         $overwrite Whether this overwrites an instance with
	 *                                the same `$name`
	 */
	public function __construct(
		public string $name,
		public string $className,
		public bool $overwrite=false,
	) {
	}
}
