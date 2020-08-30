<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class VoiceState extends JSONDataModel {
	public ?string $guild_id;
	/** If unset, then the user disconnected from the voice channel */
	public ?string $channel_id;
	public ?string $user_id;
	/** the guild member this voice state is for */
	public ?GuildMember $member;
	/** the session id for this voice state */
	public string $session_id;
	/** whether this user is deafened by the server */
	public bool $deaf;
	/** whether this user is muted by the server */
	public bool $mute;
	/** whether this user is locally deafened */
	public bool $self_deaf;
	/** whether this user is locally muted */
	public bool $self_mute;
	/** whether this user is streaming using "Go Live" */
	public ?bool $self_stream;
	/** whether this user's camera is enabled */
	public bool $self_video;
	/** whether this user is muted by the current user */
	public bool $suppress;
}
