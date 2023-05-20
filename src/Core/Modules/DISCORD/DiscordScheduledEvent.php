<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTime;
use Nadybot\Core\JSONDataModel;

class DiscordScheduledEvent extends JSONDataModel {
	public const PRIVACY_GUILD=2;

	public const STATUS_SCHEDULED=1;
	public const STATUS_ACTIVE=2;
	public const STATUS_COMPLETED=3;
	public const STATUS_CANCELED=4;

	public const TYPE_STAGE_INSTANCE=1;
	public const TYPE_VOICE=2;
	public const TYPE_EXTERNAL=3;

	/** the id of the scheduled event */
	public string $id;

	/** the guild id which the scheduled event belongs to */
	public string $guild_id;

	/** the channel id in which the scheduled event will be hosted, or null if scheduled entity type is EXTERNAL */
	public ?string $channel_id=null;

	/** the id of the user that created the scheduled event * */
	public ?string $creator_id=null;

	/** the name of the scheduled event (1-100 characters) */
	public string $name;

	/** the description of the scheduled event (1-1000 characters) */
	public ?string $description=null;

	/** the time the scheduled event will start */
	public DateTime $scheduled_start_time;

	/** the time the scheduled event will end, required if entity_type is EXTERNAL */
	public ?DateTime $scheduled_end_time=null;

	/** the privacy level of the scheduled event */
	public int $privacy_level;

	/** the status of the scheduled event */
	public int $status;

	/** the type of the scheduled event */
	public int $entity_type;

	/** the id of an entity associated with a guild scheduled event */
	public ?string $entity_id=null;

	/** additional metadata for the guild scheduled event */
	public ?DiscordScheduledEventMetadata $entity_metadata=null;

	/** the user that created the scheduled event */
	public ?DiscordUser $creator=null;

	/** the number of users subscribed to the scheduled event */
	public ?int $user_count=null;

	/** the cover image hash of the scheduled event */
	public ?string $image=null;
}
