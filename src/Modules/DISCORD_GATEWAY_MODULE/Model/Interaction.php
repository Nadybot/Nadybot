<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\Modules\DISCORD\{DiscordMessageIn, DiscordUser, GuildMember, ReducedStringableTrait};
use Stringable;

class Interaction implements Stringable {
	use ReducedStringableTrait;

	public const int TYPE_PING = 1;
	public const int TYPE_APPLICATION_COMMAND = 2;
	public const int TYPE_MESSAGE_COMPONENT = 3;
	public const int TYPE_APPLICATION_COMMAND_AUTOCOMPLETE = 4;
	public const int TYPE_MODAL_SUBMIT = 5;

	/**
	 * @param string            $id             id of the interaction
	 * @param string            $application_id id of the application this interaction is for
	 * @param int               $type           the type of interaction
	 * @param string            $token          a continuation token for responding to the
	 *                                          interaction
	 * @param int               $version        read-only property, always 1
	 * @param ?InteractionData  $data           the command data payload
	 * @param ?string           $guild_id       the guild it was sent from
	 * @param ?string           $channel_id     the channel it was sent from
	 * @param ?GuildMember      $member         guild member data for the invoking user,
	 *                                          including permissions
	 * @param ?DiscordUser      $user           user object for the invoking user, if invoked
	 *                                          in a DM
	 * @param ?DiscordMessageIn $message        for components, the message they were attached to
	 * @param ?string           $locale         the selected language of the invoking user
	 * @param ?string           $guild_locale   the guild's preferred locale, if invoked in a guild
	 */
	public function __construct(
		public string $id,
		public string $application_id,
		public int $type,
		#[\SensitiveParameter] public string $token,
		public int $version,
		public ?InteractionData $data=null,
		public ?string $guild_id=null,
		public ?string $channel_id=null,
		public ?GuildMember $member=null,
		public ?DiscordUser $user=null,
		public ?DiscordMessageIn $message=null,
		public ?string $locale=null,
		public ?string $guild_locale=null,
	) {
	}

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
