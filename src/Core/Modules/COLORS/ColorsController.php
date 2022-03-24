<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
};

#[
	NCA\Instance,

	NCA\Setting\Color(
		name: "default_guild_color",
		description: "default guild color",
		defaultValue: "#89D2E8",
	),
	NCA\Setting\Color(
		name: "default_priv_color",
		description: "default private channel color",
		defaultValue: "#89D2E8",
	),
	NCA\Setting\Color(
		name: "default_window_color",
		description: "default window color",
		defaultValue: "#89D2E8",
	),
	NCA\Setting\Color(
		name: "default_tell_color",
		description: "default tell color",
		defaultValue: "#89D2E8",
	),
	NCA\Setting\Color(
		name: "default_routed_sys_color",
		description: "default routed system color",
		defaultValue: "#89D2E8",
	),
	NCA\Setting\Color(
		name: "default_highlight_color",
		description: "default highlight color",
		defaultValue: "#FFFFFF",
	),
	NCA\Setting\Color(
		name: "default_header_color",
		description: "default header color",
		defaultValue: "#FFFF00",
	),
	NCA\Setting\Color(
		name: "default_header2_color",
		description: "default header2 color",
		defaultValue: "#FCA712",
	),
	NCA\Setting\Color(
		name: "default_clan_color",
		description: "default clan color",
		defaultValue: "#F79410",
	),
	NCA\Setting\Color(
		name: "default_omni_color",
		description: "default omni color",
		defaultValue: "#00FFFF",
	),
	NCA\Setting\Color(
		name: "default_neut_color",
		description: "default neut color",
		defaultValue: "#E6E1A6",
	),
	NCA\Setting\Color(
		name: "default_unknown_color",
		description: "default unknown color",
		defaultValue: "#FF0000"
	),
]
class ColorsController extends ModuleInstance {
}
