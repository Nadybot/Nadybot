<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;
use Nadybot\Core\Modules\DISCORD\{DiscordMessageIn, DiscordUser};

class Interaction extends JSONDataModel {
	public const TYPE_PING = 1;
	public const TYPE_APPLICATION_COMMAND = 2;
	public const TYPE_MESSAGE_COMPONENT = 3;
	public const TYPE_APPLICATION_COMMAND_AUTOCOMPLETE = 4;
	public const TYPE_MODAL_SUBMIT = 5;

	/** id of the interaction */
	public string $id;

	/** id of the application this interaction is for */
	public string $application_id;

	/** the type of interaction */
	public int $type;

	/** the command data payload */
	public ?InteractionData $data = null;

	/** the guild it was sent from */
	public ?string $guild_id = null;

	/** the channel it was sent from */
	public ?string $channel_id = null;

	/** guild member data for the invoking user, including permissions */
	public ?GuildMember $member = null;

	/** user object for the invoking user, if invoked in a DM */
	public ?DiscordUser $user = null;

	/** a continuation token for responding to the interaction */
	public string $token;

	/** read-only property, always 1 */
	public int $version;

	/** for components, the message they were attached to */
	public ?DiscordMessageIn $message = null;

	/** the selected language of the invoking user */
	public ?string $locale = null;

	/** the guild's preferred locale, if invoked in a guild */
	public ?string $guild_locale = null;

	public function toCommand(): ?string {
		if (!isset($this->data)) {
			return null;
		}
		$cmdOptions = null;
		if ($this->type === self::TYPE_MESSAGE_COMPONENT) {
			$cmdOptions = $this->data->custom_id ?? null;
		} elseif ($this->type === self::TYPE_APPLICATION_COMMAND) {
			$cmdOptions = $this->data->getOptionString();
		}
		if (!isset($cmdOptions)) {
			return null;
		}
		return $cmdOptions;
	}
}
