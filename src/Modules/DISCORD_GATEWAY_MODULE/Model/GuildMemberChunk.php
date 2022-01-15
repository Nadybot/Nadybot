<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class GuildMemberChunk extends JSONDataModel {
	/** the id of the guild */
	public string $guild_id;

	/**
	 * set of guild members
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember[]
	 */
	public array $members = [];

	/** the chunk index in the expected chunks for this response (0 <= chunk_index < chunk_count) */
	public int $chunk_index;

	/**  the total number of expected chunks for this response*/
	public int $chunk_count;

	/**
	 * if passing an invalid id to REQUEST_GUILD_MEMBERS, it will be returned here
	 * @var null|string[]
	 */
	public ?array $not_found = null;

	/**
	 * if passing true to REQUEST_GUILD_MEMBERS, presences of the returned members will be here
	 * @var null|object[]
	 */
	public ?array $presences = null;

	/** the nonce used in the RequestGuildMembers-request */
	public ?string $nonce = null;
}
