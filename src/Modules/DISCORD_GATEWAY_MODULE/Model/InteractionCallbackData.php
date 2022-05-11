<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;
use Nadybot\Core\Modules\DISCORD\DiscordEmbed;

class InteractionCallbackData extends JSONDataModel {
	/** is the response TTS */
	public ?bool $tts = null;

	/** message content */
	public ?string $content = null;

	/**
	 * supports up to 10 embeds
	 * @var null|\Nadybot\Core\Modules\DISCORD\DiscordEmbed[]
	 */
	public ?array $embeds = null;

	/** allowed mentions object */
	public ?object $allowed_mentions = null;

	/** message flags combined as a bitfield (only SUPPRESS_EMBEDS and EPHEMERAL can be set) */
	public ?int $flags = null;

	/**
	 * message components
	 * @var null|object[]
	 */
	public ?array $components = null;

	/**
	 * attachment objects with filename and description
	 * @var null|object[]
	 */
	public ?array $attachments = null;
}
