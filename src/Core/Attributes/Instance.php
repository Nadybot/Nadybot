<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class is an instance that can be injected */
#[Attribute(Attribute::TARGET_CLASS)]
class Instance {
	/**
	 * This class is an instance that can be injected
	 *
	 * @param null|string $name      The object class name to inject
	 * @param bool        $overwrite Whether to overwrite an existing instance
	 */
	public function __construct(
		public ?string $name=null,
		public bool $overwrite=false
	) {
	}
}
