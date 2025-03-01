<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\JSON;

use Attribute;

/** Change the name of this property before exporting to JSON via JsonExporter */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Name {
	public function __construct(public string $name) {
	}
}
