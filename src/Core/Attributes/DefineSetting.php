<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS|Attribute::IS_REPEATABLE)]
class DefineSetting {
	/**
	 * Register a setting for this module
	 *
	 * @param string $name The name of the setting
	 * @param string $description A description for the setting (will appear in the config)
	 * @param string $type 'color', 'number', 'text', 'options', or 'time'
	 * @param int|float|string  $defaultValue
	 * @param string $mode 'edit' or 'noedit'
	 * @param array<string|int,int|string> $options An optional list of values that the setting can be, semi-colon delimited.
	 *                                              Alternatively, use an associative array [label => value], where label is optional.
	 * @param string $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param ?string $help A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $type,
		public int|float|string|bool $defaultValue,
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
	}
}
