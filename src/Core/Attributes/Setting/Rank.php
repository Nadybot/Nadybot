<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\Attributes\DefineSetting;
use Nadybot\Core\Types\{AccessLevel, SettingMode};

#[Attribute(Attribute::TARGET_PROPERTY)]
class Rank extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param null|int|float|string|bool|list<mixed> $defaultValue
	 * @param array<string|int,int|string>           $options      An optional list of values that the setting can be, semi-colon delimited.
	 *                                                             Alternatively, use an associative array [label => value], where label is optional.
	 */
	public function __construct(
		string $type='rank',
		?string $name=null,
		null|int|float|string|bool|array $defaultValue=null,
		SettingMode $mode=SettingMode::Edit,
		array $options=[],
		AccessLevel $accessLevel=AccessLevel::Mod,
		?string $help=null,
		?bool $confidential=false,
	) {
		parent::__construct(
			type: $type,
			name: $name,
			defaultValue: $defaultValue,
			mode: $mode,
			options: $options,
			accessLevel: $accessLevel,
			help: $help,
			confidential: $confidential,
		);
		$this->type = 'rank';
	}
}
