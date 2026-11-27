<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTimeImmutable;
use Stringable;

class DiscordScheduledEvent implements Stringable {
	use ReducedStringableTrait;

	public const int PRIVACY_GUILD=2;

	public const int STATUS_SCHEDULED=1;
	public const int STATUS_ACTIVE=2;
	public const int STATUS_COMPLETED=3;
	public const int STATUS_CANCELED=4;

	public const int TYPE_STAGE_INSTANCE=1;
	public const int TYPE_VOICE=2;
	public const int TYPE_EXTERNAL=3;

	/**
	 * @param string                         $id                   the id of the scheduled event
	 * @param string                         $guild_id             the guild id which the
	 *                                                             scheduled event belongs to
	 * @param string                         $name                 the name of the scheduled event
	 *                                                             (1-100 characters)
	 * @param int                            $privacy_level        the privacy level of the
	 *                                                             scheduled event
	 * @param int                            $status               the status of the scheduled event
	 * @param int                            $entity_type          the type of the scheduled event
	 * @param DateTimeImmutable              $scheduled_start_time the time the scheduled event
	 *                                                             will start
	 * @param ?string                        $channel_id           the channel id in which the
	 *                                                             scheduled event will be hosted,
	 *                                                             or null if scheduled entity type
	 *                                                             is EXTERNAL
	 * @param ?string                        $creator_id           the id of the user that created
	 *                                                             the scheduled event *
	 * @param ?string                        $description          the description of the scheduled
	 *                                                             event (1-1000 characters)
	 * @param ?DateTimeImmutable             $scheduled_end_time   the time the scheduled event
	 *                                                             will end, required if
	 *                                                             entity_type is EXTERNAL
	 * @param ?string                        $entity_id            the id of an entity associated
	 *                                                             with a guild scheduled event
	 * @param ?DiscordScheduledEventMetadata $entity_metadata      additional metadata for the
	 *                                                             guild scheduled event
	 * @param ?DiscordUser                   $creator              the user that created the
	 *                                                             scheduled event
	 * @param ?int                           $user_count           the number of users subscribed
	 *                                                             to the scheduled event
	 * @param ?string                        $image                the cover image hash of the
	 *                                                             scheduled event
	 */
	public function __construct(
		public string $id,
		public string $guild_id,
		public string $name,
		public int $privacy_level,
		public int $status,
		public int $entity_type,
		public DateTimeImmutable $scheduled_start_time,
		public ?string $channel_id=null,
		public ?string $creator_id=null,
		public ?string $description=null,
		public ?DateTimeImmutable $scheduled_end_time=null,
		public ?string $entity_id=null,
		public ?DiscordScheduledEventMetadata $entity_metadata=null,
		public ?DiscordUser $creator=null,
		public ?int $user_count=null,
		public ?string $image=null,
	) {
	}
}
