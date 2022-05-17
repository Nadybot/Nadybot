<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

class DiscordButtonComponent extends DiscordComponent {
	public const STYLE_PRIMARY = 1;
	public const STYLE_SECONDARY = 2;
	public const STYLE_SUCCESS = 3;
	public const STYLE_DANGER = 4;
	public const STYLE_LINK = 5;

	public int $type = 2;
	/** one of button styles */
	public int $style;

	/** text that appears on the button, max 80 characters */
	public string $label;

	/** name, id, and animated */
	public ?object $emoji = null;

	/** a developer-defined identifier for the button, max 100 characters */
	public ?string $custom_id = null;

	/** a url for link-style buttons */
	public ?string $url = null;

	/** whether the button is disabled (default false) */
	public bool $disabled = false;
}
