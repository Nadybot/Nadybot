<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'discord_emoji')]
class DBEmoji extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $name,
		public string $guild_id,
		public string $emoji_id,
		public int $registered,
		public int $version,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
