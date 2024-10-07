<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Safe\json_encode;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

use Stringable;

class ApplicationCommand implements Stringable {
	use ReducedStringableTrait;

	/** Slash commands; a text-based command that shows up when a user types / */
	public const TYPE_CHAT_INPUT = 1;

	/** A UI-based command that shows up when you right click or tap on a user */
	public const TYPE_USER = 2;

	/** A UI-based command that shows up when you right click or tap on a message */
	public const TYPE_MESSAGE = 3;

	/**
	 * @param ?string                     $id                         Unique ID of command
	 * @param ?string                     $application_id             ID of the parent application
	 * @param ?string                     $guild_id                   guild id of the command, if not global
	 * @param string                      $name                       Name of command, 1-32 characters
	 * @param ?string                     $version                    Auto-incrementing version identifier updated
	 *                                                                during substantial record changes
	 * @param string                      $description                Description for CHAT_INPUT commands,
	 *                                                                1-100 characters.
	 *                                                                Empty string for USER and MESSAGE commands
	 * @param int                         $type                       Type of command, defaults to 1
	 * @param ?array<string,string>       $name_localizations         Localization dictionary for name field.
	 *                                                                Values follow the same restrictions as name
	 * @param ?array<string,string>       $description_localizations  Localization dictionary for description field.
	 *                                                                Values follow the same restrictions as description
	 * @param ?ApplicationCommandOption[] $options                    Parameters for the command, max of 25
	 * @param ?string                     $default_member_permissions Set of permissions represented as a bit set
	 * @param ?bool                       $dm_permission              Indicates whether the command is available in
	 *                                                                DMs with the app, only for globally-scoped
	 *                                                                commands. By default, commands are visible.
	 * @param ?bool                       $default_permission         Not recommended for use as field will soon
	 *                                                                be deprecated.
	 *                                                                Indicates whether the command is enabled by
	 *                                                                default when the app is added to a guild,
	 *                                                                defaults to true
	 *
	 * @psalm-param ?list<ApplicationCommandOption> $options
	 */
	public function __construct(
		public ?string $id,
		public ?string $application_id,
		public ?string $guild_id,
		public string $name,
		public ?string $version,
		public string $description,
		public int $type=self::TYPE_CHAT_INPUT,
		public ?array $name_localizations=null,
		public ?array $description_localizations=null,
		#[CastListToType(ApplicationCommandOption::class)] public ?array $options=null,
		public ?string $default_member_permissions=null,
		public ?bool $dm_permission=true,
		public ?bool $default_permission=null,
	) {
	}

	public function isSameAs(self $cmd): bool {
		foreach (get_object_vars($this) as $key => $myValue) {
			if (in_array($key, ['id', 'application_id', 'version'], true)) {
				continue;
			}
			if (is_array($myValue)) {
				if (json_encode($cmd->{$key}) !== json_encode($myValue)) {
					return false;
				}
			} elseif ($cmd->{$key} !== $myValue) {
				return false;
			}
		}
		return true;
	}
}
