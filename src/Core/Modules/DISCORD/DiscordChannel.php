<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTimeImmutable;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Stringable;

class DiscordChannel implements Stringable {
	use ReducedStringableTrait;

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

	/** a channel that users can follow and cross-post into their own server */
	public const GUILD_ANNOUNCEMENT = 5;

	/** a temporary sub-channel within a GUILD_ANNOUNCEMENT channel */
	public const ANNOUNCEMENT_THREAD = 10;

	/** a temporary sub-channel within a GUILD_TEXT or GUILD_FORUM channel */
	public const PUBLIC_THREAD = 11;

	/** a temporary sub-channel within a GUILD_TEXT channel that is only viewable by those invited and those with the MANAGE_THREADS permission */
	public const PRIVATE_THREAD = 12;

	/** a voice channel for hosting events with an audience */
	public const GUILD_STAGE_VOICE = 13;

	/** the channel in a hub containing the listed servers */
	public const GUILD_DIRECTORY = 14;

	/** Channel that can only contain threads */
	public const GUILD_FORUM = 15;

	/**
	 * @param ?int               $position              sorting position of the channel
	 * @param ?array<mixed>      $permission_overwrites explicit permission overwrites for members
	 *                                                  and roles
	 * @param ?string            $name                  the name of the channel (2-100 characters)
	 * @param ?string            $topic                 the channel topic (0-1024 characters)
	 * @param ?bool              $nsfw                  Whether the channel is "not safe for work"
	 * @param ?string            $last_message_id       the id of the last message sent in this
	 *                                                  channel (may not point to an existing or
	 *                                                  valid message)
	 * @param ?int               $bitrate               bit rate (in bits) of the voice channel
	 * @param ?int               $user_limit            the user limit of the voice channel
	 * @param ?int               $rate_limit_per_user   amount of seconds a user has to wait
	 *                                                  before sending another message (0-21600);
	 *                                                  bots, as well as users with the permission
	 *                                                  manage_messages or manage_channel, are
	 *                                                  unaffected
	 * @param ?DiscordUser[]     $recipients            the recipients of the DM
	 * @param ?string            $icon                  icon hash
	 * @param ?string            $owner_id              id of the DM creator
	 * @param ?string            $application_id        application id of the group DM creator
	 *                                                  if it is bot-created
	 * @param ?string            $parent_id             id of the parent category for a channel
	 *                                                  (each parent category can contain up to
	 *                                                  50 channels)
	 * @param ?DateTimeImmutable $last_pin_timestamp    when the last pinned message was pinned
	 *
	 * @psalm-param ?list<DiscordUser> $recipients
	 */
	public function __construct(
		public string $id,
		public int $type,
		public ?string $guild_id=null,
		public ?int $position=null,
		public ?array $permission_overwrites=null,
		public ?string $name=null,
		public ?string $topic=null,
		public ?bool $nsfw=null,
		public ?string $last_message_id=null,
		public ?int $bitrate=null,
		public ?int $user_limit=null,
		public ?int $rate_limit_per_user=null,
		#[CastListToType(DiscordUser::class)] public ?array $recipients=null,
		public ?string $icon=null,
		public ?string $owner_id=null,
		public ?string $application_id=null,
		public ?string $parent_id=null,
		public ?DateTimeImmutable $last_pin_timestamp=null,
	) {
	}
}
