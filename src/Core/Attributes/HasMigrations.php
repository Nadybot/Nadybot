<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

/** This class has migrations that need to be executed when initializing the bot */
#[Attribute(Attribute::TARGET_CLASS)]
class HasMigrations {
	public function __construct(
		public string $dir='Migrations',
		public ?string $module=null,
	) {
	}
}
