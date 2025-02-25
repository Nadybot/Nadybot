<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\JSON;

use Attribute;

/** Ignore this value when exporting to JSON via JsonExporter */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Ignore {
	public function __construct() {
	}
}
