<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

class Theme {
	public function __construct(
		public string $name,
		public string $description,
		public ?string $window_color=null,
		public ?string $priv_color=null,
		public ?string $tell_color=null,
		public ?string $guild_color=null,
		public ?string $routed_sys_color=null,
		public ?string $header_color=null,
		public ?string $header2_color=null,
		public ?string $highlight_color=null,
		public ?string $clan_color=null,
		public ?string $omni_color=null,
		public ?string $neut_color=null,
		public ?string $unknown_color=null,
	) {
	}

	/** @return array<string,?string> */
	public function getColors(): array {
		return [
			'window_color' => $this->window_color,
			'priv_color' => $this->priv_color,
			'tell_color' => $this->tell_color,
			'guild_color' => $this->guild_color,
			'routed_sys_color' => $this->routed_sys_color,
			'header_color' => $this->header_color,
			'header2_color' => $this->header2_color,
			'highlight_color' => $this->highlight_color,
			'clan_color' => $this->clan_color,
			'omni_color' => $this->omni_color,
			'neut_color' => $this->neut_color,
			'unknown_color' => $this->unknown_color,
		];
	}
}
