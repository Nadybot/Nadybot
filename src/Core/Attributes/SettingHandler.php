<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class is a setting handler for the given setting type */
#[Attribute(Attribute::TARGET_CLASS)]
class SettingHandler {
	public function __construct(public string $name) {
	}
}
