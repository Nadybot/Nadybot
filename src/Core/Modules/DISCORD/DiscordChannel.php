<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTime;
use Nadybot\Core\JSONDataModel;

class DiscordChannel extends JSONDataModel {
	/** text channel within a server */
	public const GUILD_TEXT = 0;
	/** a direct message between users */
	public const DM = 1;
	/** a voice channel within a server */
	public const GUILD_VOICE = 2;
	/** a direct message between multiple users */
	public const GROUP_DM = 3;
	/** an organizational category that contains up to 50 channels */
	public const GUILD_CATEGORY = 4;
	/** a channel that users can follow and crosspost into their own server */
	public const GUILD_NEWS = 5;
	/** a channel in which game developers can sell their game on Discord */
	public const GUILD_STORE = 6	;

	public string $id;
	public int $type;
	public ?string $guild_id = null;
	/** sorting position of the channel */
	public ?int $position = null;
	/** explicit permission overwrites for members and roles */
	public ?array $permission_overwrites = null;
	/** the name of the channel (2-100 characters) */
	public ?string $name = null;
	/** the channel topic (0-1024 characters) */
	public ?string $topic = null;
	/** Whether the channel is "not safe for work" */
	public ?bool $nsfw;
	/**
	 * the id of the last message sent in this channel
	 * (may not point to an existing or valid message)
	 */
	public ?string $last_message_id = null;
	/** bitrate (in bits) of the voice channel */
	public ?int $bitrate = null;
	/** the user limit of the voice channel */
	public ?int $user_limit = null;
	/**
	 * amount of seconds a user has to wait before sending another message (0-21600);
	 * bots, as well as users with the permission manage_messages or manage_channel,
	 * are unaffected
	 */
	public ?int $rate_limit_per_user = null;
	/**
	 * the recipients of the DM
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\User[]
	 */
	public ?array $recipients = null;
	/** icon hash */
	public ?string $icon = null;
	/** id of the DM creator */
	public ?string $owner_id = null;
	/** application id of the group DM creator if it is bot-created */
	public ?string $application_id = null;
	/**
	 * id of the parent category for a channel
	 * (each parent category can contain up to 50 channels)
	 */
	public ?string $parent_id = null;
	/** when the last pinned message was pinned */
	public ?DateTime $last_pin_timestamp = null;
}
