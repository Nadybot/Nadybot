<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\COLORS;

use Spatie\DataTransferObject\DataTransferObject;

class Theme extends DataTransferObject {
	public string $name;
	public string $description;
	public ?string $window_color = null;
	public ?string $priv_color = null;
	public ?string $tell_color = null;
	public ?string $guild_color = null;
	public ?string $routed_sys_color = null;
	public ?string $header_color = null;
	public ?string $header2_color = null;
	public ?string $highlight_color = null;
	public ?string $clan_color = null;
	public ?string $omni_color = null;
	public ?string $neut_color = null;
	public ?string $unknown_color = null;
}
