<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This method should be called, whenever the given setting changes */
#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class SettingChangeHandler {
	public function __construct(
		public string $setting,
	) {
	}
}
