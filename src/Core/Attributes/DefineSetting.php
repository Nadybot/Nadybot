<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use Attribute;
use Exception;
use Nadybot\Core\Types\SettingMode;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DefineSetting {
	/**
	 * Register a setting for this module
	 *
	 * @param string                                 $type         'color', 'number', 'text', 'options', or 'time'
	 * @param null|string                            $name         The name of the setting
	 * @param null|int|float|string|bool|list<mixed> $defaultValue
	 * @param SettingMode                            $mode         'edit' or 'noedit'
	 * @param array<string|int,int|string>           $options      An optional list of values that the setting can be, semi-colon delimited.
	 *                                                             Alternatively, use an associative array [label => value], where label is optional.
	 * @param string                                 $accessLevel  The permission level needed to change this setting (default: mod) (optional)
	 * @param ?string                                $help         A help file for this setting; if blank, will use a help topic with the same name as this setting if it exists (optional)
	 * @param ?bool                                  $confidential Is this setting confidential and shouldn't show up outside of PMs?
	 */
	public function __construct(
		public string $type,
		public ?string $name=null,
		public null|int|float|string|bool|array $defaultValue=null,
		public SettingMode $mode=SettingMode::Edit,
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
		public ?bool $confidential=false,
	) {
	}

	/** @return int|float|string|bool|list<mixed> */
	public function getValue(): int|float|string|bool|array {
		if (!isset($this->defaultValue)) {
			throw new Exception('No defaultValue set or given.');
		}
		return $this->defaultValue;
	}
}
