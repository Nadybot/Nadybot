<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTimeImmutable;
use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Attributes\CastToStdClass;
use stdClass;
use Stringable;

class DiscordMessageIn implements Stringable {
	use ReducedStringableTrait;

	/**
	 * @param ?DiscordUser         $author            user object the author of this message
	 *                                                (not guaranteed to be a valid user, see below)
	 * @param string               $content           The actual content of the message
	 * @param DateTimeImmutable    $timestamp         when this message was sent
	 * @param ?GuildMember         $member            partial guild member object member properties
	 *                                                for this message's author
	 * @param DiscordUser[]        $mentions
	 * @param array<mixed>         $mention_roles
	 * @param ?array<mixed>        $mention_channels
	 * @param ?DiscordAttachment[] $attachments
	 * @param DiscordEmbed[]       $embeds
	 * @param ?array<mixed>        $reactions
	 * @param ?MessageActivity     $activity          sent with Rich Presence-related chat embeds
	 * @param ?stdClass            $application       sent with Rich Presence-related chat embeds
	 * @param ?stdClass            $message_reference reference data sent with cross-posted messages
	 * @param ?int                 $flags             message flags ORd together, describes extra
	 *                                                features of the message
	 *
	 * @psalm-param list<DiscordEmbed> $embeds
	 * @psalm-param list<DiscordUser>  $mentions
	 */
	public function __construct(
		public string $id,
		public string $channel_id,
		public ?DiscordUser $author,
		public string $content,
		public DateTimeImmutable $timestamp,
		public int $type,
		public ?string $guild_id=null,
		public ?GuildMember $member=null,
		public ?DateTimeImmutable $edited_timestamp=null,
		public bool $tts=false,
		public bool $mention_everyone=false,
		#[CastListToType(DiscordUser::class)] public array $mentions=[],
		public array $mention_roles=[],
		public ?array $mention_channels=null,
		#[CastListToType(DiscordAttachment::class)] public ?array $attachments=[],
		#[CastListToType(DiscordEmbed::class)] public array $embeds=[],
		public ?array $reactions=null,
		public mixed $nonce=null,
		public bool $pinned=false,
		public ?string $webhook_id=null,
		public ?MessageActivity $activity=null,
		#[CastToStdClass] public ?stdClass $application=null,
		#[CastToStdClass] public ?stdClass $message_reference=null,
		public ?int $flags=null,
	) {
	}
}
