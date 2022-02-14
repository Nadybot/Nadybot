<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
	SettingManager,
};

#[NCA\Instance]
class ColorsController extends ModuleInstance {
	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_guild_color",
			description: "default guild color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#89D2E8'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_priv_color",
			description: "default private channel color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#89D2E8'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_window_color",
			description: "default window color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#89D2E8'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_tell_color",
			description: "default tell color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#89D2E8'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_routed_sys_color",
			description: "default routed system color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#89D2E8'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_highlight_color",
			description: "default highlight color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#FFFFFF'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_header_color",
			description: "default header color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#FFFF00'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_header2_color",
			description: "default header2 color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#FCA712'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_clan_color",
			description: "default clan color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#F79410'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_omni_color",
			description: "default omni color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#00FFFF'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_neut_color",
			description: "default neut color",
			mode: "edit",
			type: "color",
			accessLevel: "mod",
			value: "<font color='#E6E1A6'>",
		);

		$this->settingManager->add(
			module: $this->moduleName,
			name: "default_unknown_color",
			description: "default unknown color",
			type: "color",
			mode: "edit",
			accessLevel: "mod",
			value: "<font color='#FF0000'>"
		);
	}
}
