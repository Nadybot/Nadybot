<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class ApplicationCommandOption extends JSONDataModel {
	public const TYPE_SUB_COMMAND = 1;
	public const TYPE_SUB_COMMAND_GROUP = 2;
	public const TYPE_STRING = 3;
	/** Any integer between -2^53 and 2^53 */
	public const TYPE_INTEGER = 4;
	public const TYPE_BOOLEAN = 5;
	public const TYPE_USER = 6;
	/** Includes all channel types + categories */
	public const TYPE_CHANNEL = 7;
	public const TYPE_ROLE = 8;
	/** Includes users and roles */
	public const TYPE_MENTIONABLE = 9;
	/** Any double between -2^53 and 2^53 */
	public const TYPE_NUMBER = 10;
	/** attachment object */
	public const TYPE_ATTACHMENT = 11;

	/** Type of option */
	public int $type;

	/** 1-32 character name */
	public string $name;

	/**
	 * Localization dictionary for the name field. Values follow the same restrictions as name
	 * @var null|array<string,string>
	 */
	public ?array $name_localizations = null;

	/** 1-100 character description */
	public string $description;

	/**
	 * Localization dictionary for the description field. Values follow the same restrictions as description
	 * @var null|array<string,string>
	 */
	public ?array $description_localizations = null;

	/** If the parameter is required or optional--default false */
	public bool $required = false;

	/**
	 * Choices for STRING, INTEGER, and NUMBER types for the user to pick from, max 25
	 * @var null|\Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\ApplicationCommandOptionChoice[]
	 */
	public ?array $choices = null;

	/**
	 * If the option is a subcommand or subcommand group type, these nested options will be the parameters
	 * @var null|\Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\ApplicationCommandOption[]
	 */
	public ?array $options = null;

	/**
	 * If the option is a channel type, the channels shown will be restricted to these types
	 * @var null|array<mixed>
	 */
	public ?array $channel_types = null;

	/** If the option is an INTEGER or NUMBER type, the minimum value permitted */
	public null|int|float $min_value = null;

	/** If the option is an INTEGER or NUMBER type, the maximum value permitted */
	public null|int|float $max_value = null;

	/** If autocomplete interactions are enabled for this STRING, INTEGER, or NUMBER type option */
	public ?bool $autocomplete = null;
}
