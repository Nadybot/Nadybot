<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Stringable;

class ApplicationCommandOption implements Stringable {
	use ReducedStringableTrait;

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

	/**
	 * @param int                               $type                      Type of option
	 * @param string                            $name                      1-32 character name
	 * @param string                            $description               1-100 character description
	 * @param ?array<string,string>             $name_localizations        Localization dictionary for the name field. Values follow the same restrictions as name
	 * @param ?array<string,string>             $description_localizations Localization dictionary for the description field. Values follow the same restrictions as descriptions
	 * @param bool                              $required                  If the parameter is required or optional--default false
	 * @param ?ApplicationCommandOptionChoice[] $choices                   Choices for STRING, INTEGER, and NUMBER types for the user to pick from, max 25
	 * @param ?ApplicationCommandOption[]       $options                   If the option is a subcommand or subcommand group type, these nested options will be the parameters
	 * @param ?array<mixed>                     $channel_types             If the option is a channel type, the channels shown will be restricted to these types
	 * @param null|int|float                    $min_value                 If the option is an INTEGER or NUMBER type, the minimum value permitted
	 * @param null|int|float                    $max_value                 If the option is an INTEGER or NUMBER type, the maximum value permitted
	 * @param ?bool                             $autocomplete              If autocomplete interactions are enabled for this STRING, INTEGER, or NUMBER type option
	 *
	 * @psalm-param ?list<ApplicationCommandOptionChoice> $choices
	 * @psalm-param ?list<ApplicationCommandOption>       $options
	 */
	public function __construct(
		public int $type,
		public string $name,
		public string $description,
		public ?array $name_localizations=null,
		public ?array $description_localizations=null,
		public bool $required=false,
		#[CastListToType(ApplicationCommandOptionChoice::class)] public ?array $choices=null,
		#[CastListToType(ApplicationCommandOption::class)] public ?array $options=null,
		public ?array $channel_types=null,
		public null|int|float $min_value=null,
		public null|int|float $max_value=null,
		public ?bool $autocomplete=null,
	) {
	}
}
