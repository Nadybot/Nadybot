<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Setting\Color,
	ModuleInstance,
};

#[NCA\Instance]
class ColorsController extends ModuleInstance {
	/** default guild color */
	#[Color] public string $defaultGuildColor = "#89D2E8";

	/** default private channel color */
	#[Color] public string $defaultPrivColor = "#89D2E8";

	/** default window color */
	#[Color] public string $defaultWindowColor = "#89D2E8";

	/** default tell color */
	#[Color] public string $defaultTellColor = "#89D2E8";

	/** default routed system color */
	#[Color] public string $defaultRoutedSysColor = "#89D2E8";

	/** default highlight color */
	#[Color] public string $defaultHighlightColor = "#FFFFFF";

	/** default header color */
	#[Color] public string $defaultHeaderColor = "#FFFF00";

	/** default header2 color */
	#[Color] public string $defaultHeader2Color = "#FCA712";

	/** default clan color */
	#[Color] public string $defaultClanColor = "#F79410";

	/** default omni color */
	#[Color] public string $defaultOmniColor = "#00FFFF";

	/** default neut color */
	#[Color] public string $defaultNeutColor = "#E6E1A6";

	/** default unknown color */
	#[Color] public string $defaultUnknownColor = "#FF0000";
}
