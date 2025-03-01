<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class provides an exporter for the given export-subkey */
#[Attribute(Attribute::TARGET_CLASS)]
class Exporter {
	public function __construct(
		public string $key,
	) {
	}
}
