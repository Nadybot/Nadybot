<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Modules\DISCORD\{DiscordActionRowComponent, DiscordAllowedMentions, DiscordAttachment, DiscordEmbed, ReducedStringableTrait};
use Stringable;

class InteractionCallbackData implements Stringable {
	use ReducedStringableTrait;

	/** do not include any embeds when serializing this message */
	public const SUPPRESS_EMBEDS = 4;

	/** this message is only visible to the user who invoked the Interaction */
	public const EPHEMERAL = 64;

	/**
	 * @param ?bool                        $tts              is the response TTS
	 * @param ?string                      $content          message content
	 * @param ?DiscordEmbed[]              $embeds           supports up to 10 embeds
	 * @param ?DiscordAllowedMentions      $allowed_mentions allowed mentions object
	 * @param ?int                         $flags            message flags combined as a bit field
	 *                                                       (only SUPPRESS_EMBEDS and EPHEMERAL can be set)
	 * @param ?DiscordActionRowComponent[] $components       message components
	 * @param ?DiscordAttachment[]         $attachments      attachment objects with filename and description
	 *
	 * @psalm-param ?list<DiscordEmbed>              $embeds
	 * @psalm-param ?list<DiscordActionRowComponent> $components
	 */
	public function __construct(
		public ?bool $tts=null,
		public ?string $content=null,
		#[CastListToType(DiscordEmbed::class)] public ?array $embeds=null,
		public ?DiscordAllowedMentions $allowed_mentions=null,
		public ?int $flags=null,
		#[CastListToType(DiscordActionRowComponent::class)] public ?array $components=null,
		#[CastListToType(DiscordAttachment::class)] public ?array $attachments=null,
	) {
	}
}
