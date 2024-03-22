<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

class RequestGuildMembers {
	public ?string $query = '';
	public int $limit = 0;
	public ?bool $presences = null;

	/** @var string[] */
	public ?array $user_ids = null;
	public ?string $nonce;

	public function __construct(public string $guild_id) {
	}
}
