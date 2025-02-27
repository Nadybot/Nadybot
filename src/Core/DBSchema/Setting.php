<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\ColName;
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{Attributes as NCA, DBTable, Types\SettingMode};

/** This table stores all the settings and there current values in the bot */
#[NCA\DB\Table(name: 'settings')]
class Setting extends DBTable {
	/**
	 * @param string           $name         Name of the setting
	 * @param SettingMode      $mode         Editable or non-editable
	 * @param null|string      $module       Which module defines this setting
	 * @param null|string      $type         The type of setting (text, number, option list, …)
	 * @param null|string      $description  A short text describing what this setting does
	 * @param null|string      $source       Where does this setting come from (usually `'db'`)
	 * @param null|AccessLevel $access_level Access level required to change this setting
	 * @param null|string      $help         A filename with a long help for this setting, or `null` if not needed
	 * @param null|string      $value        The current value for this setting
	 * @param null|string      $options      A semicolon-separated list of pre-defined values
	 * @param null|string      $intoptions   A semicolon-separated list of pre-defined integer values
	 * @param null|int         $verify       Internal flag to track if a setting is still defined by the bot
	 * @param null|bool        $confidential Set to `true` if this setting's value should
	 *                                       be redacted when displaying it publicly.
	 *                                       Will only show the real value in DMs
	 */
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public SettingMode $mode,
		public ?string $module=null,
		public ?string $type=null,
		public ?string $description=null,
		public ?string $source=null,
		#[ColName('admin')] public ?AccessLevel $access_level=null,
		public ?string $help=null,
		public ?string $value='0',
		public ?string $options='0',
		public ?string $intoptions='0',
		public ?int $verify=0,
		public ?bool $confidential=false,
	) {
	}
}
