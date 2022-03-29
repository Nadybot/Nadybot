<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Exception;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DefineSetting {
	/**
	 * Register a setting for this module
	 *
	 * @param string $type 'color', 'number', 'text', 'options', or 'time'
	 * @param null|string $name The name of the setting
	 * @param null|int|float|string|bool  $defaultValue
	 * @param string $mode 'edit' or 'noedit'
	 * @param array<string|int,int|string> $options An optional list of values that the setting can be, semi-colon delimited.
	 *                                              Alternatively, use an associative array [label => value], where label is optional.
	 * @param string $accessLevel The permission level needed to change this setting (default: mod) (optional)
	 * @param ?string $help A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 */
	public function __construct(
		public string $type,
		public ?string $name=null,
		public null|int|float|string|bool $defaultValue=null,
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
	) {
	}

	public function getValue(): int|float|string|bool {
		if (!isset($this->defaultValue)) {
			throw new Exception("No defaultValue set or given.");
		}
		return $this->defaultValue;
	}
}
