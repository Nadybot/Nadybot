<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use function Safe\json_encode;

use Nadybot\Core\JSONDataModel;

class ApplicationCommand extends JSONDataModel {
	/** Slash commands; a text-based command that shows up when a user types / */
	public const TYPE_CHAT_INPUT = 1;

	/** A UI-based command that shows up when you right click or tap on a user */
	public const TYPE_USER = 2;

	/** A UI-based command that shows up when you right click or tap on a message */
	public const TYPE_MESSAGE = 3;

	/** Unique ID of command */
	public string $id;

	/** Type of command, defaults to 1 */
	public int $type = self::TYPE_CHAT_INPUT;

	/** ID of the parent application */
	public string $application_id;

	/** guild id of the command, if not global */
	public ?string $guild_id;

	/** Name of command, 1-32 characters */
	public string $name;

	/**
	 * Localization dictionary for name field. Values follow the same restrictions as name
	 *
	 * @var array<string,string>
	 */
	public ?array $name_localizations = null;

	/** Description for CHAT_INPUT commands, 1-100 characters. Empty string for USER and MESSAGE commands */
	public string $description;

	/**
	 * Localization dictionary for description field. Values follow the same restrictions as description
	 *
	 * @var array<string,string>
	 */
	public ?array $description_localizations = null;

	/**
	 * Parameters for the command, max of 25
	 *
	 * @var null|\Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\ApplicationCommandOption[]
	 */
	public ?array $options = null;

	/** Set of permissions represented as a bit set */
	public ?string $default_member_permissions = null;

	/** Indicates whether the command is available in DMs with the app, only for globally-scoped commands. By default, commands are visible. */
	public ?bool $dm_permission = true;

	/** Not recommended for use as field will soon be deprecated. Indicates whether the command is enabled by default when the app is added to a guild, defaults to true */
	public ?bool $default_permission = null;

	/** Autoincrementing version identifier updated during substantial record changes */
	public string $version;

	public function isSameAs(self $cmd): bool {
		$cmp = clone $cmd;
		unset($cmp->id);
		unset($cmp->application_id);
		unset($cmp->version);
		$cmp->default_permission = null;
		return json_encode($this) === json_encode($cmp);
	}
}
