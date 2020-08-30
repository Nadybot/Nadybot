<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Activity;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;
use DateTime;

class DiscordMessageIn extends JSONDataModel {
	public string $id;
	public string $channel_id;
	public ?string $guild_id = null;
	/** user object	the author of this message (not guaranteed to be a valid user, see below) */
	public ?DiscordUser $author;
	/**
	 * partial guild member object
	 * member properties for this message's author
	 */
	public ?GuildMember $member = null;
	/** The actual content of the message */
	public string $content;
	/** when this message was sent */
	public DateTime $timestamp;
	public ?DateTime $edited_timestamp = null;
	public bool $tts = false;
	public bool $mention_everyone = false;
	/** @var \Nadybot\Core\Modules\DISCORD\DiscordUser[] */
	public array $mentions = [];
	public array $mention_roles = [];
	public ?array $mention_channels = null;
	public ?array $attachments = [];
	public array $embeds = [];
	public ?array $reactions = null;
	public $nonce = null;
	public bool $pinned = false;
	public ?string $webhook_id = null;
	public int $type;
	/** sent with Rich Presence-related chat embeds */
	public ?Activity $activity = null;
	/** sent with Rich Presence-related chat embeds */
	public ?object $application = null;
	/** reference data sent with crossposted messages */
	public ?object $message_reference = null;
	/** message flags ORd together, describes extra features of the message */
	public ?int $flags = null;
}
