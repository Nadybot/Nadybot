<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use DateTime;
use Nadybot\Core\JSONDataModel;
use Nadybot\Core\Modules\DISCORD\DiscordUser;

class GuildMember extends JSONDataModel {
	public ?DiscordUser $user;
	/** this users guild nickname */
	public ?string $nick;
	/**
	 * array of role object ids
	 * @var string[]
	 */
	public array $roles;
	/** when the user joined the guild */
	public DateTime $joined_at;
	/** when the user started boosting the guild */
	public ?DateTime $premium_since;
	/** whether the user is deafened in voice channels */
	public bool $deaf;
	/** whether the user is muted in voice channels */
	public bool $mute;

	public function getName(): string {
		if (isset($this->nick)) {
			return $this->nick;
		}
		if (isset($this->user)) {
			return $this->user->username . "#" . $this->user->discriminator;
		}
		return "UnknownUser";
	}
}
