<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class ModuleAccessLevel extends SettingOption {
	/** Higher value means fewer rights. Use this to sort on */
	public int $numeric_value = 0;
}
