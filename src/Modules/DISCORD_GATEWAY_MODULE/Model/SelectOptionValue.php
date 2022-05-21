<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class SelectOptionValue extends JSONDataModel {
	/** the user-facing name of the option, max 100 characters */
	public string $label;

	/** the dev-defined value of the option, max 100 characters */
	public string $value;

	/** an additional description of the option, max 100 characters */
	public ?string $description = null;

	/** id, name, and animated */
	public ?Emoji $emoji = null;

	/** will render this option as selected by default */
	public ?bool $default = null;
}
