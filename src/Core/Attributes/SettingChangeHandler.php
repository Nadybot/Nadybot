<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD|Attribute::IS_REPEATABLE)]
class SettingChangeHandler {
	public function __construct(
		public string $setting,
	) {
	}
}
