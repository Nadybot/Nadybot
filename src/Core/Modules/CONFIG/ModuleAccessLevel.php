<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

/** This represents an access level on the bot */
class ModuleAccessLevel extends SettingOption {
	/**
	 * @param string     $name          Name of this option for displaying
	 * @param int|string $value         Which value does this option represent?
	 * @param int        $numeric_value Higher value means fewer rights. Use this to sort on
	 * @param bool       $enabled       Some ranks only work if a module is enabled
	 */
	public function __construct(
		public string $name,
		public int|string $value,
		public int $numeric_value=0,
		public bool $enabled=true,
	) {
	}
}
