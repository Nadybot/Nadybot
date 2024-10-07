<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Stringable;

/**
 * For the attachments array in Message Create/Edit requests, only the id is required.
 *
 * @link https://discord.com/developers/docs/resources/channel#attachment-object
 */
class DiscordAttachment implements Stringable {
	use ReducedStringableTrait;

	/**
	 * @param string  $id            attachment id
	 * @param ?string $filename      name of file attached
	 * @param ?string $description   description for the file (max 1024 characters)
	 * @param ?string $content_type  the attachment's media type
	 * @param ?int    $size          size of file in bytes
	 * @param ?string $url           source url of file
	 * @param ?string $proxy_url     a proxied url of file
	 * @param ?int    $height        height of file (if image)
	 * @param ?int    $width         width of file (if image)
	 * @param ?bool   $ephemeral     whether this attachment is ephemeral
	 * @param ?float  $duration_secs the duration of the audio file (currently for voice messages)
	 * @param ?string $waveform      base64 encoded byte array representing a sampled waveform (currently for voice messages)
	 * @param ?int    $flags         attachment flags combined as a bit-field
	 */
	public function __construct(
		public string $id,
		public ?string $filename=null,
		public ?string $description=null,
		public ?string $content_type=null,
		public ?int $size=null,
		public ?string $url=null,
		public ?string $proxy_url=null,
		public ?int $height=null,
		public ?int $width=null,
		public ?bool $ephemeral=null,
		public ?float $duration_secs=null,
		public ?string $waveform=null,
		public ?int $flags=null,
	) {
	}
}
