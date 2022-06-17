<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class ApplicationCommandOptionChoice extends JSONDataModel {
	/** 1-100 character choice name */
	public string $name;

	/**
	 * Localization dictionary for the name field. Values follow the same restrictions as name
	 *
	 * @var null|array<string,string>
	 */
	public ?array $name_localizations = null;

	/** Value for the choice, up to 100 characters if string */
	public string|int|float $value;
}
