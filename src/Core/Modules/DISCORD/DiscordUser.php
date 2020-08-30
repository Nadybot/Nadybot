<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;

class DiscordUser extends JSONDataModel {
	public string $id;
	/** the user's username, not unique across the platform */
	public string $username;
	/** the user's 4-digit discord-tag */
	public string $discriminator;
	/** the user's avatar hash */
	public ?string $avatar = null;
	/** whether the user belongs to an OAuth2 application */
	public ?bool $bot = null;
	/**
	 * whether the user is an Official Discord System user
	 * (part of the urgent message system)
	 */
	public ?bool $system = null;
	/** whether the user has two factor enabled on their account */
	public ?bool $mfa_enabled = null;
	/** the user's chosen language option */
	public ?string $locale = null;
	/** whether the email on this account has been verified */
	public ?bool $verified = null;
	/** the user's email */
	public ?string $email = null;
	/** the flags on a user's account */
	public ?int $flags = null;
	/** the type of Nitro subscription on a user's account */
	public ?int $premium_type = null;
	/** the public flags on a user's account */
	public ?int $public_flags = null;
}
