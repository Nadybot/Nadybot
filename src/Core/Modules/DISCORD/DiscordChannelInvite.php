<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTime;
use Nadybot\Core\JSONDataModel;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Guild;

class DiscordChannelInvite extends JSONDataModel {
	public string $code;

	/** Partial guild object */
	public ?Guild $guild = null;

	/** The channel this invite is for */
	public DiscordChannel $channel;

	/** The user who created the invite */
	public ?DiscordUser $inviter = null;

	/** the type of target for this voice channel invite */
	public ?int $target_type = null;

	/** the user whose stream to display for this voice channel stream invite */
	public ?DiscordUser $target_user = null;

	/**
	 * approximate count of online members, returned from the
	 * GET /invites/<code> endpoint when with_counts is true
	 */
	public ?int $approximate_presence_count = null;

	/**
	 * approximate count of total members, returned from the
	 * GET /invites/<code> endpoint when with_counts is true
	 */
	public ?int $approximate_member_count = null;

	/**
	 * the expiration date of this invite, returned from the
	 * GET /invites/<code> endpoint when with_expiration is true
	 */
	public ?DateTime $expires_at = null;
}
